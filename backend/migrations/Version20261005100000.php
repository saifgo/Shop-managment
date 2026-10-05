<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workshop costing: raw materials, recipes, material consumption, moving-average stock cost, reorder levels';
    }

    public function up(Schema $schema): void
    {
        // Raw materials vs finished goods, and the unit stock is counted in.
        $this->addSql('ALTER TABLE products ADD kind VARCHAR(16) DEFAULT \'finished_good\' NOT NULL');
        $this->addSql('ALTER TABLE products ADD unit VARCHAR(16) DEFAULT \'pc\' NOT NULL');
        $this->addSql('ALTER TABLE product_variants ADD reorder_level NUMERIC(19, 4) DEFAULT NULL');

        // Stock cost: moving average per balance, and the cost each movement was valued at.
        $this->addSql('ALTER TABLE stock_balances ADD average_cost NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('ALTER TABLE stock_movements ADD unit_cost NUMERIC(19, 4) DEFAULT NULL');

        // Existing stock is valued at the price of the latest purchase receipt of the same item, when there is one.
        $this->addSql(<<<'SQL'
            UPDATE stock_balances b SET average_cost = COALESCE((
                SELECT poi.unit_price_amount
                FROM purchase_receipt_items pri
                JOIN purchase_order_items poi ON poi.id = pri.purchase_order_item_id
                JOIN purchase_receipts pr ON pr.id = pri.purchase_receipt_id
                WHERE poi.variant_id = b.variant_id
                ORDER BY pr.created_at DESC
                LIMIT 1
            ), 0)
            SQL);

        // Recipes (bills of materials).
        $this->addSql('CREATE TABLE product_components (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, component_variant_id VARCHAR(26) NOT NULL, quantity_per_unit NUMERIC(19, 4) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCT_COMPONENT ON product_components (variant_id, component_variant_id)');
        $this->addSql('CREATE INDEX IDX_PRODUCT_COMPONENT_COMPONENT ON product_components (component_variant_id)');
        $this->addSql('ALTER TABLE product_components ADD CONSTRAINT FK_PRODUCT_COMPONENT_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_components ADD CONSTRAINT FK_PRODUCT_COMPONENT_COMPONENT FOREIGN KEY (component_variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Production: what an order drew from stock and what the rest of it cost.
        $this->addSql('ALTER TABLE production_orders ADD additional_cost NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('ALTER TABLE production_orders ADD materials_consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE production_items ADD material_cost NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('CREATE TABLE production_materials (id VARCHAR(26) NOT NULL, production_order_id VARCHAR(26) NOT NULL, production_item_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, unit_cost NUMERIC(19, 4) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PRODUCTION_MATERIALS_ORDER ON production_materials (production_order_id)');
        $this->addSql('CREATE INDEX IDX_PRODUCTION_MATERIALS_ITEM ON production_materials (production_item_id)');
        $this->addSql('CREATE INDEX IDX_PRODUCTION_MATERIALS_VARIANT ON production_materials (variant_id)');
        $this->addSql('ALTER TABLE production_materials ADD CONSTRAINT FK_PRODUCTION_MATERIALS_ORDER FOREIGN KEY (production_order_id) REFERENCES production_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_materials ADD CONSTRAINT FK_PRODUCTION_MATERIALS_ITEM FOREIGN KEY (production_item_id) REFERENCES production_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_materials ADD CONSTRAINT FK_PRODUCTION_MATERIALS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE production_materials');
        $this->addSql('ALTER TABLE production_items DROP material_cost');
        $this->addSql('ALTER TABLE production_orders DROP materials_consumed_at');
        $this->addSql('ALTER TABLE production_orders DROP additional_cost');
        $this->addSql('DROP TABLE product_components');
        $this->addSql('ALTER TABLE stock_movements DROP unit_cost');
        $this->addSql('ALTER TABLE stock_balances DROP average_cost');
        $this->addSql('ALTER TABLE product_variants DROP reorder_level');
        $this->addSql('ALTER TABLE products DROP unit');
        $this->addSql('ALTER TABLE products DROP kind');
    }
}
