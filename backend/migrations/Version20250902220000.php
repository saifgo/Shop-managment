<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sales and inventory tables for Phase 3';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_locations (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(128) NOT NULL, is_default BOOLEAN DEFAULT false NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STOCK_LOCATION_CODE ON stock_locations (company_id, code)');

        $this->addSql('CREATE TABLE stock_balances (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, location_id VARCHAR(26) NOT NULL, physical_on_hand NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, reserved NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STOCK_BALANCE ON stock_balances (company_id, variant_id, location_id)');
        $this->addSql('ALTER TABLE stock_balances ADD CONSTRAINT FK_STOCK_BALANCES_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_balances ADD CONSTRAINT FK_STOCK_BALANCES_LOCATION FOREIGN KEY (location_id) REFERENCES stock_locations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE stock_movements (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, location_id VARCHAR(26) NOT NULL, movement_type VARCHAR(32) NOT NULL, quantity_delta NUMERIC(19, 4) NOT NULL, reserved_delta NUMERIC(19, 4) NOT NULL, source_type VARCHAR(64) NOT NULL, source_id VARCHAR(26) NOT NULL, reference VARCHAR(128) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_STOCK_MOVEMENTS_VARIANT ON stock_movements (variant_id)');
        $this->addSql('CREATE INDEX IDX_STOCK_MOVEMENTS_SOURCE ON stock_movements (source_type, source_id)');
        $this->addSql('ALTER TABLE stock_movements ADD CONSTRAINT FK_STOCK_MOVEMENTS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_movements ADD CONSTRAINT FK_STOCK_MOVEMENTS_LOCATION FOREIGN KEY (location_id) REFERENCES stock_locations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE stock_adjustments (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, location_id VARCHAR(26) NOT NULL, movement_id VARCHAR(26) NOT NULL, quantity_delta NUMERIC(19, 4) NOT NULL, reason VARCHAR(255) NOT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE stock_adjustments ADD CONSTRAINT FK_STOCK_ADJ_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_adjustments ADD CONSTRAINT FK_STOCK_ADJ_LOCATION FOREIGN KEY (location_id) REFERENCES stock_locations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_adjustments ADD CONSTRAINT FK_STOCK_ADJ_MOVEMENT FOREIGN KEY (movement_id) REFERENCES stock_movements (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE orders (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, currency VARCHAR(3) NOT NULL, subtotal_amount NUMERIC(19, 4) NOT NULL, tax_total_amount NUMERIC(19, 4) NOT NULL, discount_total_amount NUMERIC(19, 4) NOT NULL, grand_total_amount NUMERIC(19, 4) NOT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_REFERENCE ON orders (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ORDER_IDEMPOTENCY ON orders (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_ORDERS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE order_items (id VARCHAR(26) NOT NULL, order_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, product_id VARCHAR(26) NOT NULL, product_name VARCHAR(200) NOT NULL, variant_name VARCHAR(200) NOT NULL, sku VARCHAR(64) NOT NULL, quantity_ordered NUMERIC(19, 4) NOT NULL, quantity_reserved NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, quantity_backordered NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, quantity_delivered NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, unit_price_amount NUMERIC(19, 4) NOT NULL, unit_price_currency VARCHAR(3) NOT NULL, tax_rate NUMERIC(8, 4) NOT NULL, discount_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, line_subtotal_amount NUMERIC(19, 4) NOT NULL, line_tax_amount NUMERIC(19, 4) NOT NULL, line_total_amount NUMERIC(19, 4) NOT NULL, line_status VARCHAR(32) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_ORDER_ITEMS_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE order_items ADD CONSTRAINT FK_ORDER_ITEMS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE order_status_history (id VARCHAR(26) NOT NULL, order_id VARCHAR(26) NOT NULL, from_status VARCHAR(32) NOT NULL, to_status VARCHAR(32) NOT NULL, changed_by VARCHAR(26) DEFAULT NULL, reason TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE order_status_history ADD CONSTRAINT FK_ORDER_STATUS_HISTORY_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE reservations (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, order_item_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, location_id VARCHAR(26) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_RESERVATIONS_ORDER_ITEM ON reservations (order_item_id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_RESERVATIONS_ORDER_ITEM FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_RESERVATIONS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_RESERVATIONS_LOCATION FOREIGN KEY (location_id) REFERENCES stock_locations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE demand_allocations (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, order_item_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, fulfilled_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, status VARCHAR(32) NOT NULL, priority INT DEFAULT 100 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DEMAND_CUSTOMER ON demand_allocations (customer_id)');
        $this->addSql('CREATE INDEX IDX_DEMAND_VARIANT ON demand_allocations (variant_id)');
        $this->addSql('ALTER TABLE demand_allocations ADD CONSTRAINT FK_DEMAND_ORDER_ITEM FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_allocations ADD CONSTRAINT FK_DEMAND_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE demand_allocations ADD CONSTRAINT FK_DEMAND_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demand_allocations DROP CONSTRAINT FK_DEMAND_CUSTOMER');
        $this->addSql('ALTER TABLE demand_allocations DROP CONSTRAINT FK_DEMAND_VARIANT');
        $this->addSql('ALTER TABLE demand_allocations DROP CONSTRAINT FK_DEMAND_ORDER_ITEM');
        $this->addSql('DROP TABLE demand_allocations');
        $this->addSql('ALTER TABLE reservations DROP CONSTRAINT FK_RESERVATIONS_LOCATION');
        $this->addSql('ALTER TABLE reservations DROP CONSTRAINT FK_RESERVATIONS_VARIANT');
        $this->addSql('ALTER TABLE reservations DROP CONSTRAINT FK_RESERVATIONS_ORDER_ITEM');
        $this->addSql('DROP TABLE reservations');
        $this->addSql('ALTER TABLE order_status_history DROP CONSTRAINT FK_ORDER_STATUS_HISTORY_ORDER');
        $this->addSql('DROP TABLE order_status_history');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_ORDER_ITEMS_VARIANT');
        $this->addSql('ALTER TABLE order_items DROP CONSTRAINT FK_ORDER_ITEMS_ORDER');
        $this->addSql('DROP TABLE order_items');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT FK_ORDERS_CUSTOMER');
        $this->addSql('DROP TABLE orders');
        $this->addSql('ALTER TABLE stock_adjustments DROP CONSTRAINT FK_STOCK_ADJ_MOVEMENT');
        $this->addSql('ALTER TABLE stock_adjustments DROP CONSTRAINT FK_STOCK_ADJ_LOCATION');
        $this->addSql('ALTER TABLE stock_adjustments DROP CONSTRAINT FK_STOCK_ADJ_VARIANT');
        $this->addSql('DROP TABLE stock_adjustments');
        $this->addSql('ALTER TABLE stock_movements DROP CONSTRAINT FK_STOCK_MOVEMENTS_LOCATION');
        $this->addSql('ALTER TABLE stock_movements DROP CONSTRAINT FK_STOCK_MOVEMENTS_VARIANT');
        $this->addSql('DROP TABLE stock_movements');
        $this->addSql('ALTER TABLE stock_balances DROP CONSTRAINT FK_STOCK_BALANCES_LOCATION');
        $this->addSql('ALTER TABLE stock_balances DROP CONSTRAINT FK_STOCK_BALANCES_VARIANT');
        $this->addSql('DROP TABLE stock_balances');
        $this->addSql('DROP TABLE stock_locations');
    }
}
