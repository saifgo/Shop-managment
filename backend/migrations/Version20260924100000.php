<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tax rates become percentages everywhere and the default rate becomes a company setting.
 *
 * Before this, CartService stored order_items.tax_rate as a fraction (0.2000) while
 * document lines treat tax_rate as a percentage, so documents built from orders were
 * taxed at 0.2% instead of 20%.
 *
 * - order_items.tax_rate: fraction -> percentage. Order amounts were already right; only the unit changes.
 * - Draft (unposted) documents built from orders: lines are re-taxed at the correct rate and
 *   document totals recomputed, mirroring DocumentSnapshotBuilder (bcmath truncates, hence TRUNC).
 * - Posted documents are left untouched: they are numbered legal records, and their stored
 *   0.2000 already reads correctly as the 0.2% that was actually charged. They are listed in
 *   the migration output so they can be corrected with credit notes / reissued.
 */
final class Version20260924100000 extends AbstractMigration
{
    /** Lines copied from order items that still carry a fractional rate. */
    private const ORDER_DERIVED_LINE = 'l.source_line_id IN (SELECT id FROM order_items) AND l.tax_rate <= 1';

    public function getDescription(): string
    {
        return 'Add company_settings for a configurable tax rate; store order tax rates as percentages and fix draft documents built from orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_settings (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, setting_key VARCHAR(64) NOT NULL, setting_value VARCHAR(255) NOT NULL, updated_by VARCHAR(26) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_COMPANY_SETTING_KEY ON company_settings (company_id, setting_key)');

        // Queries run now see the pre-migration data; addSql statements run after up() returns.
        $draftIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT d.id FROM commercial_documents d JOIN document_lines l ON l.document_id = d.id'
            .' WHERE d.is_posted = false AND '.self::ORDER_DERIVED_LINE,
        );
        $posted = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT d.document_type, d.document_number FROM commercial_documents d JOIN document_lines l ON l.document_id = d.id'
            .' WHERE d.is_posted = true AND '.self::ORDER_DERIVED_LINE.' ORDER BY d.document_type, d.document_number',
        );

        if ($draftIds !== []) {
            // Postgres evaluates every SET expression against the old row, so tax_rate below is still the fraction.
            $this->addSql(
                'UPDATE document_lines l SET'
                .' line_tax_amount = TRUNC((l.line_subtotal_amount - l.discount_amount) * l.tax_rate, 4),'
                .' line_total_amount = (l.line_subtotal_amount - l.discount_amount) + TRUNC((l.line_subtotal_amount - l.discount_amount) * l.tax_rate, 4),'
                .' tax_rate = l.tax_rate * 100'
                .' WHERE l.document_id IN (:ids) AND '.self::ORDER_DERIVED_LINE,
                ['ids' => $draftIds],
                ['ids' => ArrayParameterType::STRING],
            );
            $this->addSql(
                'UPDATE commercial_documents d SET'
                .' tax_total_amount = t.tax_total, grand_total_amount = t.grand_total, updated_at = NOW()'
                .' FROM (SELECT document_id, SUM(line_tax_amount) AS tax_total, SUM(line_total_amount) AS grand_total'
                .' FROM document_lines GROUP BY document_id) t'
                .' WHERE t.document_id = d.id AND d.id IN (:ids)',
                ['ids' => $draftIds],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->addSql('UPDATE order_items SET tax_rate = tax_rate * 100 WHERE tax_rate <= 1');

        $this->write(sprintf('Re-taxed %d draft document(s) built from orders.', count($draftIds)));

        if ($posted !== []) {
            $this->warnIf(true, sprintf(
                '%d posted document(s) built from orders were taxed at 0.2%% instead of 20%% and were NOT changed: %s. Issue credit notes and reissue them if needed.',
                count($posted),
                implode(', ', array_map(
                    static fn (array $row): string => $row['document_number'] ?? $row['document_type'],
                    $posted,
                )),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Re-taxed draft documents are not reverted: restoring the 0.2% amounts would reintroduce the bug.
        $this->addSql('UPDATE order_items SET tax_rate = tax_rate / 100');
        $this->addSql('DROP TABLE company_settings');
    }
}
