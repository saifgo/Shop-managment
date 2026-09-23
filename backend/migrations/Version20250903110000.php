<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250903110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create returns, purchasing, and lightweight finance tables for Phase 6';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE return_requests (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, order_id VARCHAR(26) NOT NULL, replacement_delivery_id VARCHAR(26) DEFAULT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, resolution VARCHAR(32) DEFAULT NULL, reason TEXT DEFAULT NULL, notes TEXT DEFAULT NULL, credit_note_id VARCHAR(26) DEFAULT NULL, requested_by VARCHAR(26) DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RETURN_REFERENCE ON return_requests (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RETURN_IDEMPOTENCY ON return_requests (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE return_requests ADD CONSTRAINT FK_RETURN_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE return_requests ADD CONSTRAINT FK_RETURN_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE return_requests ADD CONSTRAINT FK_RETURN_REPLACEMENT_DELIVERY FOREIGN KEY (replacement_delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE return_items (id VARCHAR(26) NOT NULL, return_request_id VARCHAR(26) NOT NULL, order_item_id VARCHAR(26) NOT NULL, delivery_line_id VARCHAR(26) DEFAULT NULL, quantity NUMERIC(19, 4) NOT NULL, reason TEXT DEFAULT NULL, item_condition VARCHAR(32) DEFAULT NULL, inspection_notes TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE return_items ADD CONSTRAINT FK_RETURN_ITEM_REQUEST FOREIGN KEY (return_request_id) REFERENCES return_requests (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE return_items ADD CONSTRAINT FK_RETURN_ITEM_ORDER_ITEM FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE return_items ADD CONSTRAINT FK_RETURN_ITEM_DELIVERY_LINE FOREIGN KEY (delivery_line_id) REFERENCES delivery_lines (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE return_events (id VARCHAR(26) NOT NULL, return_request_id VARCHAR(26) NOT NULL, event_type VARCHAR(32) NOT NULL, from_status VARCHAR(32) DEFAULT NULL, to_status VARCHAR(32) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE return_events ADD CONSTRAINT FK_RETURN_EVENT_REQUEST FOREIGN KEY (return_request_id) REFERENCES return_requests (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE suppliers (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(200) NOT NULL, contact_email VARCHAR(255) DEFAULT NULL, contact_phone VARCHAR(64) DEFAULT NULL, address TEXT DEFAULT NULL, tax_id VARCHAR(64) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUPPLIER_CODE ON suppliers (company_id, code)');

        $this->addSql('CREATE TABLE supplier_products (id VARCHAR(26) NOT NULL, supplier_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, supplier_sku VARCHAR(64) DEFAULT NULL, purchase_price_amount NUMERIC(19, 4) NOT NULL, purchase_price_currency VARCHAR(3) NOT NULL, lead_time_days INT DEFAULT NULL, minimum_order_qty NUMERIC(19, 4) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUPPLIER_VARIANT ON supplier_products (supplier_id, variant_id)');
        $this->addSql('ALTER TABLE supplier_products ADD CONSTRAINT FK_SUPPLIER_PRODUCT_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supplier_products ADD CONSTRAINT FK_SUPPLIER_PRODUCT_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE purchase_orders (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, supplier_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, currency VARCHAR(3) NOT NULL, expected_at DATE DEFAULT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PO_REFERENCE ON purchase_orders (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PO_IDEMPOTENCY ON purchase_orders (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE purchase_orders ADD CONSTRAINT FK_PO_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE purchase_order_items (id VARCHAR(26) NOT NULL, purchase_order_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, quantity_ordered NUMERIC(19, 4) NOT NULL, quantity_received NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, unit_price_amount NUMERIC(19, 4) NOT NULL, unit_price_currency VARCHAR(3) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE purchase_order_items ADD CONSTRAINT FK_PO_ITEM_PO FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_order_items ADD CONSTRAINT FK_PO_ITEM_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE purchase_receipts (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, purchase_order_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECEIPT_REFERENCE ON purchase_receipts (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECEIPT_IDEMPOTENCY ON purchase_receipts (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE purchase_receipts ADD CONSTRAINT FK_RECEIPT_PO FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE purchase_receipt_items (id VARCHAR(26) NOT NULL, purchase_receipt_id VARCHAR(26) NOT NULL, purchase_order_item_id VARCHAR(26) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE purchase_receipt_items ADD CONSTRAINT FK_RECEIPT_ITEM_RECEIPT FOREIGN KEY (purchase_receipt_id) REFERENCES purchase_receipts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_receipt_items ADD CONSTRAINT FK_RECEIPT_ITEM_PO_ITEM FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE supplier_invoices (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, supplier_id VARCHAR(26) NOT NULL, purchase_order_id VARCHAR(26) DEFAULT NULL, invoice_number VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, currency VARCHAR(3) NOT NULL, total_amount NUMERIC(19, 4) NOT NULL, amount_paid NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, issued_at DATE NOT NULL, due_date DATE DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUPPLIER_INVOICE_NUMBER ON supplier_invoices (company_id, invoice_number)');
        $this->addSql('ALTER TABLE supplier_invoices ADD CONSTRAINT FK_SUPPLIER_INVOICE_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supplier_invoices ADD CONSTRAINT FK_SUPPLIER_INVOICE_PO FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE supplier_payments (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, supplier_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, amount NUMERIC(19, 4) NOT NULL, allocated_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, currency VARCHAR(3) NOT NULL, method VARCHAR(32) NOT NULL, payment_date DATE NOT NULL, status VARCHAR(32) NOT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUPPLIER_PAYMENT_REFERENCE ON supplier_payments (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUPPLIER_PAYMENT_IDEMPOTENCY ON supplier_payments (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_SUPPLIER_PAYMENT_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE supplier_payment_allocations (id VARCHAR(26) NOT NULL, payment_id VARCHAR(26) NOT NULL, invoice_id VARCHAR(26) NOT NULL, allocated_amount NUMERIC(19, 4) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE supplier_payment_allocations ADD CONSTRAINT FK_SUPPLIER_ALLOC_PAYMENT FOREIGN KEY (payment_id) REFERENCES supplier_payments (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE supplier_payment_allocations ADD CONSTRAINT FK_SUPPLIER_ALLOC_INVOICE FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE finance_categories (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, type VARCHAR(32) NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FINANCE_CATEGORY ON finance_categories (company_id, type, code)');

        $this->addSql('CREATE TABLE incomes (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, category_id VARCHAR(26) NOT NULL, source VARCHAR(200) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, income_date DATE NOT NULL, reference VARCHAR(128) DEFAULT NULL, attachment_ref VARCHAR(512) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE incomes ADD CONSTRAINT FK_INCOME_CATEGORY FOREIGN KEY (category_id) REFERENCES finance_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE expenses (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, category_id VARCHAR(26) NOT NULL, supplier_id VARCHAR(26) DEFAULT NULL, payee VARCHAR(200) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, expense_date DATE NOT NULL, attachment_ref VARCHAR(512) DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE expenses ADD CONSTRAINT FK_EXPENSE_CATEGORY FOREIGN KEY (category_id) REFERENCES finance_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE expenses ADD CONSTRAINT FK_EXPENSE_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE scheduled_transactions (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, category_id VARCHAR(26) NOT NULL, type VARCHAR(32) NOT NULL, description VARCHAR(200) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, recurrence VARCHAR(32) NOT NULL, next_run_at DATE NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, attachment_ref VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE scheduled_transactions ADD CONSTRAINT FK_SCHEDULED_CATEGORY FOREIGN KEY (category_id) REFERENCES finance_categories (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_transactions DROP CONSTRAINT FK_SCHEDULED_CATEGORY');
        $this->addSql('DROP TABLE scheduled_transactions');
        $this->addSql('ALTER TABLE expenses DROP CONSTRAINT FK_EXPENSE_SUPPLIER');
        $this->addSql('ALTER TABLE expenses DROP CONSTRAINT FK_EXPENSE_CATEGORY');
        $this->addSql('DROP TABLE expenses');
        $this->addSql('ALTER TABLE incomes DROP CONSTRAINT FK_INCOME_CATEGORY');
        $this->addSql('DROP TABLE incomes');
        $this->addSql('DROP TABLE finance_categories');
        $this->addSql('ALTER TABLE supplier_payment_allocations DROP CONSTRAINT FK_SUPPLIER_ALLOC_INVOICE');
        $this->addSql('ALTER TABLE supplier_payment_allocations DROP CONSTRAINT FK_SUPPLIER_ALLOC_PAYMENT');
        $this->addSql('DROP TABLE supplier_payment_allocations');
        $this->addSql('ALTER TABLE supplier_payments DROP CONSTRAINT FK_SUPPLIER_PAYMENT_SUPPLIER');
        $this->addSql('DROP TABLE supplier_payments');
        $this->addSql('ALTER TABLE supplier_invoices DROP CONSTRAINT FK_SUPPLIER_INVOICE_PO');
        $this->addSql('ALTER TABLE supplier_invoices DROP CONSTRAINT FK_SUPPLIER_INVOICE_SUPPLIER');
        $this->addSql('DROP TABLE supplier_invoices');
        $this->addSql('ALTER TABLE purchase_receipt_items DROP CONSTRAINT FK_RECEIPT_ITEM_PO_ITEM');
        $this->addSql('ALTER TABLE purchase_receipt_items DROP CONSTRAINT FK_RECEIPT_ITEM_RECEIPT');
        $this->addSql('DROP TABLE purchase_receipt_items');
        $this->addSql('ALTER TABLE purchase_receipts DROP CONSTRAINT FK_RECEIPT_PO');
        $this->addSql('DROP TABLE purchase_receipts');
        $this->addSql('ALTER TABLE purchase_order_items DROP CONSTRAINT FK_PO_ITEM_VARIANT');
        $this->addSql('ALTER TABLE purchase_order_items DROP CONSTRAINT FK_PO_ITEM_PO');
        $this->addSql('DROP TABLE purchase_order_items');
        $this->addSql('ALTER TABLE purchase_orders DROP CONSTRAINT FK_PO_SUPPLIER');
        $this->addSql('DROP TABLE purchase_orders');
        $this->addSql('ALTER TABLE supplier_products DROP CONSTRAINT FK_SUPPLIER_PRODUCT_VARIANT');
        $this->addSql('ALTER TABLE supplier_products DROP CONSTRAINT FK_SUPPLIER_PRODUCT_SUPPLIER');
        $this->addSql('DROP TABLE supplier_products');
        $this->addSql('DROP TABLE suppliers');
        $this->addSql('ALTER TABLE return_events DROP CONSTRAINT FK_RETURN_EVENT_REQUEST');
        $this->addSql('DROP TABLE return_events');
        $this->addSql('ALTER TABLE return_items DROP CONSTRAINT FK_RETURN_ITEM_DELIVERY_LINE');
        $this->addSql('ALTER TABLE return_items DROP CONSTRAINT FK_RETURN_ITEM_ORDER_ITEM');
        $this->addSql('ALTER TABLE return_items DROP CONSTRAINT FK_RETURN_ITEM_REQUEST');
        $this->addSql('DROP TABLE return_items');
        $this->addSql('ALTER TABLE return_requests DROP CONSTRAINT FK_RETURN_REPLACEMENT_DELIVERY');
        $this->addSql('ALTER TABLE return_requests DROP CONSTRAINT FK_RETURN_ORDER');
        $this->addSql('ALTER TABLE return_requests DROP CONSTRAINT FK_RETURN_CUSTOMER');
        $this->addSql('DROP TABLE return_requests');
    }
}
