<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Ulid;

final class Version20260925100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-product production orders: per-product quantities for each stage execution';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stage_execution_lines (id VARCHAR(26) NOT NULL, stage_execution_id VARCHAR(26) NOT NULL, production_item_id VARCHAR(26) NOT NULL, input_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, accepted_output_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, loss_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STAGE_EXECUTION_LINE ON stage_execution_lines (stage_execution_id, production_item_id)');
        $this->addSql('CREATE INDEX IDX_STAGE_EXEC_LINE_ITEM ON stage_execution_lines (production_item_id)');
        $this->addSql('ALTER TABLE stage_execution_lines ADD CONSTRAINT FK_STAGE_EXEC_LINE_EXEC FOREIGN KEY (stage_execution_id) REFERENCES stage_executions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stage_execution_lines ADD CONSTRAINT FK_STAGE_EXEC_LINE_ITEM FOREIGN KEY (production_item_id) REFERENCES production_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE production_losses ADD production_item_id VARCHAR(26) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_PRODUCTION_LOSS_ITEM ON production_losses (production_item_id)');
        $this->addSql('ALTER TABLE production_losses ADD CONSTRAINT FK_PRODUCTION_LOSS_ITEM FOREIGN KEY (production_item_id) REFERENCES production_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Until now every order had exactly one product, so each started stage's totals belong to it.
        // Queries run now see the pre-migration data; addSql statements run after up() returns.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT se.id AS execution_id, pi.id AS item_id, se.input_quantity, se.accepted_output_quantity, se.loss_quantity
             FROM stage_executions se
             JOIN production_items pi ON pi.production_order_id = se.production_order_id
             WHERE se.status <> :pending',
            ['pending' => 'PENDING'],
        );

        foreach ($rows as $row) {
            $this->addSql(
                'INSERT INTO stage_execution_lines (id, stage_execution_id, production_item_id, input_quantity, accepted_output_quantity, loss_quantity) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    (string) new Ulid(),
                    $row['execution_id'],
                    $row['item_id'],
                    $row['input_quantity'],
                    $row['accepted_output_quantity'],
                    $row['loss_quantity'],
                ],
            );
        }

        $this->addSql('UPDATE production_losses SET production_item_id = (
            SELECT pi.id FROM stage_executions se
            JOIN production_items pi ON pi.production_order_id = se.production_order_id
            WHERE se.id = production_losses.stage_execution_id
            LIMIT 1
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_losses DROP CONSTRAINT FK_PRODUCTION_LOSS_ITEM');
        $this->addSql('DROP INDEX IDX_PRODUCTION_LOSS_ITEM');
        $this->addSql('ALTER TABLE production_losses DROP production_item_id');
        $this->addSql('ALTER TABLE stage_execution_lines DROP CONSTRAINT FK_STAGE_EXEC_LINE_ITEM');
        $this->addSql('ALTER TABLE stage_execution_lines DROP CONSTRAINT FK_STAGE_EXEC_LINE_EXEC');
        $this->addSql('DROP TABLE stage_execution_lines');
    }
}
