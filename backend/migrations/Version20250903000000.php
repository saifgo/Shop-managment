<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create production workflow tables for Phase 4';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_stages (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, sequence INT NOT NULL, name VARCHAR(128) NOT NULL, can_record_quantity BOOLEAN DEFAULT true NOT NULL, can_record_loss BOOLEAN DEFAULT true NOT NULL, reconciliation_mode VARCHAR(32) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTION_STAGE_SEQ ON production_stages (company_id, sequence)');

        $this->addSql('CREATE TABLE production_loss_reasons (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, code VARCHAR(32) NOT NULL, label VARCHAR(128) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_LOSS_REASON_CODE ON production_loss_reasons (company_id, code)');

        $this->addSql('CREATE TABLE production_orders (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, reference VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, priority VARCHAR(16) NOT NULL, source_type VARCHAR(64) DEFAULT NULL, source_id VARCHAR(26) DEFAULT NULL, planned_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, planned_due TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, notes TEXT DEFAULT NULL, created_by VARCHAR(26) DEFAULT NULL, assigned_manager VARCHAR(26) DEFAULT NULL, idempotency_key VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTION_REFERENCE ON production_orders (company_id, reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTION_IDEMPOTENCY ON production_orders (company_id, idempotency_key)');

        $this->addSql('CREATE TABLE production_items (id VARCHAR(26) NOT NULL, production_order_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, planned_quantity NUMERIC(19, 4) NOT NULL, accepted_output_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE production_items ADD CONSTRAINT FK_PRODUCTION_ITEMS_ORDER FOREIGN KEY (production_order_id) REFERENCES production_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_items ADD CONSTRAINT FK_PRODUCTION_ITEMS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE stage_executions (id VARCHAR(26) NOT NULL, production_order_id VARCHAR(26) NOT NULL, production_stage_id VARCHAR(26) NOT NULL, stage_sequence INT NOT NULL, status VARCHAR(32) NOT NULL, input_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, accepted_output_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, loss_quantity NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, notes TEXT DEFAULT NULL, performed_by VARCHAR(26) DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STAGE_EXECUTION ON stage_executions (production_order_id, production_stage_id)');
        $this->addSql('ALTER TABLE stage_executions ADD CONSTRAINT FK_STAGE_EXEC_ORDER FOREIGN KEY (production_order_id) REFERENCES production_orders (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stage_executions ADD CONSTRAINT FK_STAGE_EXEC_STAGE FOREIGN KEY (production_stage_id) REFERENCES production_stages (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE production_losses (id VARCHAR(26) NOT NULL, stage_execution_id VARCHAR(26) NOT NULL, loss_reason_id VARCHAR(26) DEFAULT NULL, reason_code VARCHAR(32) DEFAULT NULL, quantity NUMERIC(19, 4) NOT NULL, notes TEXT DEFAULT NULL, recorded_by VARCHAR(26) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE production_losses ADD CONSTRAINT FK_PRODUCTION_LOSS_EXEC FOREIGN KEY (stage_execution_id) REFERENCES stage_executions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE production_losses ADD CONSTRAINT FK_PRODUCTION_LOSS_REASON FOREIGN KEY (loss_reason_id) REFERENCES production_loss_reasons (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_losses DROP CONSTRAINT FK_PRODUCTION_LOSS_REASON');
        $this->addSql('ALTER TABLE production_losses DROP CONSTRAINT FK_PRODUCTION_LOSS_EXEC');
        $this->addSql('DROP TABLE production_losses');
        $this->addSql('ALTER TABLE stage_executions DROP CONSTRAINT FK_STAGE_EXEC_STAGE');
        $this->addSql('ALTER TABLE stage_executions DROP CONSTRAINT FK_STAGE_EXEC_ORDER');
        $this->addSql('DROP TABLE stage_executions');
        $this->addSql('ALTER TABLE production_items DROP CONSTRAINT FK_PRODUCTION_ITEMS_VARIANT');
        $this->addSql('ALTER TABLE production_items DROP CONSTRAINT FK_PRODUCTION_ITEMS_ORDER');
        $this->addSql('DROP TABLE production_items');
        $this->addSql('DROP TABLE production_orders');
        $this->addSql('DROP TABLE production_loss_reasons');
        $this->addSql('DROP TABLE production_stages');
    }
}
