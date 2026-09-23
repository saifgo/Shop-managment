<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity, audit, and outbox tables for Phase 1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE permissions (id VARCHAR(26) NOT NULL, code VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL, module VARCHAR(64) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PERMISSIONS_CODE ON permissions (code)');

        $this->addSql('CREATE TABLE roles (id VARCHAR(26) NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ROLES_CODE ON roles (code)');

        $this->addSql('CREATE TABLE users (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, is_portal_user BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_EMAIL ON users (email)');

        $this->addSql('CREATE TABLE role_permissions (role_id VARCHAR(26) NOT NULL, permission_id VARCHAR(26) NOT NULL, PRIMARY KEY(role_id, permission_id))');
        $this->addSql('CREATE INDEX IDX_ROLE_PERMISSIONS_ROLE ON role_permissions (role_id)');
        $this->addSql('CREATE INDEX IDX_ROLE_PERMISSIONS_PERMISSION ON role_permissions (permission_id)');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_ROLE_PERMISSIONS_ROLE FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_ROLE_PERMISSIONS_PERMISSION FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE user_roles (user_id VARCHAR(26) NOT NULL, role_id VARCHAR(26) NOT NULL, PRIMARY KEY(user_id, role_id))');
        $this->addSql('CREATE INDEX IDX_USER_ROLES_USER ON user_roles (user_id)');
        $this->addSql('CREATE INDEX IDX_USER_ROLES_ROLE ON user_roles (role_id)');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_USER_ROLES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_USER_ROLES_ROLE FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE refresh_tokens (id VARCHAR(26) NOT NULL, user_id VARCHAR(26) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_REFRESH_TOKENS_HASH ON refresh_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_REFRESH_TOKENS_USER ON refresh_tokens (user_id)');
        $this->addSql('ALTER TABLE refresh_tokens ADD CONSTRAINT FK_REFRESH_TOKENS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE audit_events (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) DEFAULT NULL, actor_user_id VARCHAR(26) DEFAULT NULL, action VARCHAR(128) NOT NULL, entity_type VARCHAR(128) DEFAULT NULL, entity_id VARCHAR(26) DEFAULT NULL, payload JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, correlation_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AUDIT_EVENTS_ACTION ON audit_events (action)');
        $this->addSql('CREATE INDEX IDX_AUDIT_EVENTS_OCCURRED ON audit_events (occurred_at)');

        $this->addSql('CREATE TABLE outbox_messages (id VARCHAR(26) NOT NULL, aggregate_type VARCHAR(128) NOT NULL, aggregate_id VARCHAR(26) NOT NULL, event_type VARCHAR(128) NOT NULL, payload JSON NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_OUTBOX_UNPUBLISHED ON outbox_messages (published_at, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_tokens DROP CONSTRAINT FK_REFRESH_TOKENS_USER');
        $this->addSql('ALTER TABLE user_roles DROP CONSTRAINT FK_USER_ROLES_USER');
        $this->addSql('ALTER TABLE user_roles DROP CONSTRAINT FK_USER_ROLES_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP CONSTRAINT FK_ROLE_PERMISSIONS_ROLE');
        $this->addSql('ALTER TABLE role_permissions DROP CONSTRAINT FK_ROLE_PERMISSIONS_PERMISSION');

        $this->addSql('DROP TABLE outbox_messages');
        $this->addSql('DROP TABLE audit_events');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE permissions');
    }
}
