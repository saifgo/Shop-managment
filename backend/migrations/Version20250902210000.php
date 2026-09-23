<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog and customer tables for Phase 2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE categories (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, parent_id VARCHAR(26) DEFAULT NULL, name VARCHAR(128) NOT NULL, slug VARCHAR(160) NOT NULL, sort_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CATEGORIES_COMPANY ON categories (company_id)');
        $this->addSql('CREATE INDEX IDX_CATEGORIES_PARENT ON categories (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CATEGORIES_SLUG ON categories (company_id, slug)');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_CATEGORIES_PARENT FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE catalog_attributes (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, type VARCHAR(32) NOT NULL, options JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CATALOG_ATTR_CODE ON catalog_attributes (company_id, code)');

        $this->addSql('CREATE TABLE products (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, category_id VARCHAR(26) DEFAULT NULL, name VARCHAR(200) NOT NULL, slug VARCHAR(220) NOT NULL, description TEXT DEFAULT NULL, visibility VARCHAR(16) NOT NULL, backorder_policy VARCHAR(16) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_COMPANY ON products (company_id)');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_CATEGORY ON products (category_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRODUCTS_SLUG ON products (company_id, slug)');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCTS_CATEGORY FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE product_variants (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, product_id VARCHAR(26) NOT NULL, sku VARCHAR(64) NOT NULL, name VARCHAR(200) NOT NULL, base_price_amount NUMERIC(19, 4) NOT NULL, base_price_currency VARCHAR(3) NOT NULL, attributes JSON NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_VARIANTS_PRODUCT ON product_variants (product_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_VARIANT_SKU ON product_variants (company_id, sku)');
        $this->addSql('ALTER TABLE product_variants ADD CONSTRAINT FK_VARIANTS_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE product_media (id VARCHAR(26) NOT NULL, product_id VARCHAR(26) NOT NULL, url VARCHAR(512) NOT NULL, alt_text VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PRODUCT_MEDIA_PRODUCT ON product_media (product_id)');
        $this->addSql('ALTER TABLE product_media ADD CONSTRAINT FK_PRODUCT_MEDIA_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE price_lists (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, code VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, currency VARCHAR(3) NOT NULL, is_default BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRICE_LIST_CODE ON price_lists (company_id, code)');

        $this->addSql('CREATE TABLE price_list_items (id VARCHAR(26) NOT NULL, price_list_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PRICE_LIST_VARIANT ON price_list_items (price_list_id, variant_id)');
        $this->addSql('ALTER TABLE price_list_items ADD CONSTRAINT FK_PRICE_LIST_ITEMS_LIST FOREIGN KEY (price_list_id) REFERENCES price_lists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE price_list_items ADD CONSTRAINT FK_PRICE_LIST_ITEMS_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE customers (id VARCHAR(26) NOT NULL, company_id VARCHAR(26) NOT NULL, type VARCHAR(16) NOT NULL, display_name VARCHAR(200) NOT NULL, legal_name VARCHAR(200) DEFAULT NULL, tax_id VARCHAR(64) DEFAULT NULL, vat_number VARCHAR(64) DEFAULT NULL, notes TEXT DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CUSTOMERS_COMPANY ON customers (company_id)');

        $this->addSql('CREATE TABLE customer_identities (id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, type VARCHAR(64) NOT NULL, value VARCHAR(128) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_IDENTITIES_CUSTOMER ON customer_identities (customer_id)');
        $this->addSql('ALTER TABLE customer_identities ADD CONSTRAINT FK_CUSTOMER_IDENTITIES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE customer_addresses (id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, type VARCHAR(32) NOT NULL, line1 VARCHAR(200) NOT NULL, line2 VARCHAR(200) DEFAULT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(32) NOT NULL, country VARCHAR(2) NOT NULL, is_default BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_ADDRESSES_CUSTOMER ON customer_addresses (customer_id)');
        $this->addSql('ALTER TABLE customer_addresses ADD CONSTRAINT FK_CUSTOMER_ADDRESSES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE customer_contacts (id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, name VARCHAR(128) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, role VARCHAR(64) DEFAULT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_CONTACTS_CUSTOMER ON customer_contacts (customer_id)');
        $this->addSql('ALTER TABLE customer_contacts ADD CONSTRAINT FK_CUSTOMER_CONTACTS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE portal_users (id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, user_id VARCHAR(26) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PORTAL_USERS_CUSTOMER ON portal_users (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PORTAL_USERS_USER ON portal_users (user_id)');
        $this->addSql('ALTER TABLE portal_users ADD CONSTRAINT FK_PORTAL_USERS_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE portal_users ADD CONSTRAINT FK_PORTAL_USERS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE customer_price_overrides (id VARCHAR(26) NOT NULL, customer_id VARCHAR(26) NOT NULL, variant_id VARCHAR(26) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, valid_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOMER_VARIANT_OVERRIDE ON customer_price_overrides (customer_id, variant_id)');
        $this->addSql('ALTER TABLE customer_price_overrides ADD CONSTRAINT FK_PRICE_OVERRIDE_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE customer_price_overrides ADD CONSTRAINT FK_PRICE_OVERRIDE_VARIANT FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_price_overrides DROP CONSTRAINT FK_PRICE_OVERRIDE_CUSTOMER');
        $this->addSql('ALTER TABLE customer_price_overrides DROP CONSTRAINT FK_PRICE_OVERRIDE_VARIANT');
        $this->addSql('ALTER TABLE portal_users DROP CONSTRAINT FK_PORTAL_USERS_CUSTOMER');
        $this->addSql('ALTER TABLE portal_users DROP CONSTRAINT FK_PORTAL_USERS_USER');
        $this->addSql('ALTER TABLE customer_contacts DROP CONSTRAINT FK_CUSTOMER_CONTACTS_CUSTOMER');
        $this->addSql('ALTER TABLE customer_addresses DROP CONSTRAINT FK_CUSTOMER_ADDRESSES_CUSTOMER');
        $this->addSql('ALTER TABLE customer_identities DROP CONSTRAINT FK_CUSTOMER_IDENTITIES_CUSTOMER');
        $this->addSql('ALTER TABLE price_list_items DROP CONSTRAINT FK_PRICE_LIST_ITEMS_LIST');
        $this->addSql('ALTER TABLE price_list_items DROP CONSTRAINT FK_PRICE_LIST_ITEMS_VARIANT');
        $this->addSql('ALTER TABLE product_media DROP CONSTRAINT FK_PRODUCT_MEDIA_PRODUCT');
        $this->addSql('ALTER TABLE product_variants DROP CONSTRAINT FK_VARIANTS_PRODUCT');
        $this->addSql('ALTER TABLE products DROP CONSTRAINT FK_PRODUCTS_CATEGORY');
        $this->addSql('ALTER TABLE categories DROP CONSTRAINT FK_CATEGORIES_PARENT');

        $this->addSql('DROP TABLE customer_price_overrides');
        $this->addSql('DROP TABLE portal_users');
        $this->addSql('DROP TABLE customer_contacts');
        $this->addSql('DROP TABLE customer_addresses');
        $this->addSql('DROP TABLE customer_identities');
        $this->addSql('DROP TABLE customers');
        $this->addSql('DROP TABLE price_list_items');
        $this->addSql('DROP TABLE price_lists');
        $this->addSql('DROP TABLE product_media');
        $this->addSql('DROP TABLE product_variants');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE catalog_attributes');
        $this->addSql('DROP TABLE categories');
    }
}
