<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fulfillment, documents, and payments tables for Phase 5';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deliveries (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, order_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, notes TEXT DEFAULT NULL, tracking_reference VARCHAR(128) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, dispatched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DELIVERY_REFERENCE ON deliveries (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DELIVERY_IDEMPOTENCY ON deliveries (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE deliveries ADD CONSTRAINT FK_DELIVERY_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE delivery_lines (id VARCHAR(26) NOT NULL, delivery_id VARCHAR(26) NOT NULL, order_item_id VARCHAR(26) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE delivery_lines ADD CONSTRAINT FK_DELIVERY_LINE_DELIVERY FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE delivery_lines ADD CONSTRAINT FK_DELIVERY_LINE_ORDER_ITEM FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE commercial_documents (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, document_type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, document_number VARCHAR(32) DEFAULT NULL, fiscal_year INT DEFAULT NULL, customer_id VARCHAR(26) NOT NULL, order_id VARCHAR(26) DEFAULT NULL, delivery_id VARCHAR(26) DEFAULT NULL, source_document_id VARCHAR(26) DEFAULT NULL, customer_display_name VARCHAR(200) NOT NULL, customer_legal_name VARCHAR(200) DEFAULT NULL, customer_tax_id VARCHAR(64) DEFAULT NULL, customer_vat_number VARCHAR(64) DEFAULT NULL, billing_address JSON DEFAULT NULL, shipping_address JSON DEFAULT NULL, currency VARCHAR(3) NOT NULL, subtotal_amount NUMERIC(19, 4) NOT NULL, tax_total_amount NUMERIC(19, 4) NOT NULL, discount_total_amount NUMERIC(19, 4) NOT NULL, grand_total_amount NUMERIC(19, 4) NOT NULL, amount_paid NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, is_posted BOOLEAN DEFAULT false NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, due_date DATE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DOCUMENT_NUMBER ON commercial_documents (company_id, document_type, fiscal_year, document_number)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DOCUMENT_IDEMPOTENCY ON commercial_documents (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE commercial_documents ADD CONSTRAINT FK_DOCUMENT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE commercial_documents ADD CONSTRAINT FK_DOCUMENT_ORDER FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE commercial_documents ADD CONSTRAINT FK_DOCUMENT_DELIVERY FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE commercial_documents ADD CONSTRAINT FK_DOCUMENT_SOURCE FOREIGN KEY (source_document_id) REFERENCES commercial_documents (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE document_lines (id VARCHAR(26) NOT NULL, document_id VARCHAR(26) NOT NULL, source_line_id VARCHAR(26) DEFAULT NULL, description VARCHAR(255) NOT NULL, sku VARCHAR(64) NOT NULL, quantity NUMERIC(19, 4) NOT NULL, unit_price_amount NUMERIC(19, 4) NOT NULL, tax_rate NUMERIC(8, 4) NOT NULL, discount_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, line_subtotal_amount NUMERIC(19, 4) NOT NULL, line_tax_amount NUMERIC(19, 4) NOT NULL, line_total_amount NUMERIC(19, 4) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE document_lines ADD CONSTRAINT FK_DOCUMENT_LINE_DOCUMENT FOREIGN KEY (document_id) REFERENCES commercial_documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE document_relations (id VARCHAR(26) NOT NULL, source_document_id VARCHAR(26) NOT NULL, target_document_id VARCHAR(26) NOT NULL, relation_type VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE document_relations ADD CONSTRAINT FK_DOC_REL_SOURCE FOREIGN KEY (source_document_id) REFERENCES commercial_documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_relations ADD CONSTRAINT FK_DOC_REL_TARGET FOREIGN KEY (target_document_id) REFERENCES commercial_documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE document_sequences (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, document_type VARCHAR(32) NOT NULL, fiscal_year INT NOT NULL, last_number INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DOC_SEQUENCE ON document_sequences (company_id, document_type, fiscal_year)');

        $this->addSql('CREATE TABLE document_files (id VARCHAR(26) NOT NULL, document_id VARCHAR(26) NOT NULL, storage_key VARCHAR(512) NOT NULL, mime_type VARCHAR(64) NOT NULL, version INT DEFAULT 1 NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE document_files ADD CONSTRAINT FK_DOCUMENT_FILE_DOCUMENT FOREIGN KEY (document_id) REFERENCES commercial_documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE payments (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, customer_id VARCHAR(26) NOT NULL, amount NUMERIC(19, 4) NOT NULL, allocated_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, currency VARCHAR(3) NOT NULL, method VARCHAR(32) NOT NULL, payment_date DATE NOT NULL, status VARCHAR(32) NOT NULL, notes TEXT DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PAYMENT_REFERENCE ON payments (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PAYMENT_IDEMPOTENCY ON payments (company_id, idempotency_key)');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_PAYMENT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE payment_allocations (id VARCHAR(26) NOT NULL, payment_id VARCHAR(26) NOT NULL, invoice_id VARCHAR(26) NOT NULL, allocated_amount NUMERIC(19, 4) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE payment_allocations ADD CONSTRAINT FK_ALLOC_PAYMENT FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_allocations ADD CONSTRAINT FK_ALLOC_INVOICE FOREIGN KEY (invoice_id) REFERENCES commercial_documents (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_allocations DROP CONSTRAINT FK_ALLOC_INVOICE');
        $this->addSql('ALTER TABLE payment_allocations DROP CONSTRAINT FK_ALLOC_PAYMENT');
        $this->addSql('DROP TABLE payment_allocations');
        $this->addSql('ALTER TABLE payments DROP CONSTRAINT FK_PAYMENT_CUSTOMER');
        $this->addSql('DROP TABLE payments');
        $this->addSql('ALTER TABLE document_files DROP CONSTRAINT FK_DOCUMENT_FILE_DOCUMENT');
        $this->addSql('DROP TABLE document_files');
        $this->addSql('DROP TABLE document_sequences');
        $this->addSql('ALTER TABLE document_relations DROP CONSTRAINT FK_DOC_REL_TARGET');
        $this->addSql('ALTER TABLE document_relations DROP CONSTRAINT FK_DOC_REL_SOURCE');
        $this->addSql('DROP TABLE document_relations');
        $this->addSql('ALTER TABLE document_lines DROP CONSTRAINT FK_DOCUMENT_LINE_DOCUMENT');
        $this->addSql('DROP TABLE document_lines');
        $this->addSql('ALTER TABLE commercial_documents DROP CONSTRAINT FK_DOCUMENT_SOURCE');
        $this->addSql('ALTER TABLE commercial_documents DROP CONSTRAINT FK_DOCUMENT_DELIVERY');
        $this->addSql('ALTER TABLE commercial_documents DROP CONSTRAINT FK_DOCUMENT_ORDER');
        $this->addSql('ALTER TABLE commercial_documents DROP CONSTRAINT FK_DOCUMENT_CUSTOMER');
        $this->addSql('DROP TABLE commercial_documents');
        $this->addSql('ALTER TABLE delivery_lines DROP CONSTRAINT FK_DELIVERY_LINE_ORDER_ITEM');
        $this->addSql('ALTER TABLE delivery_lines DROP CONSTRAINT FK_DELIVERY_LINE_DELIVERY');
        $this->addSql('DROP TABLE delivery_lines');
        $this->addSql('ALTER TABLE deliveries DROP CONSTRAINT FK_DELIVERY_ORDER');
        $this->addSql('DROP TABLE deliveries');
    }
}
