<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system_metadata foundation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE system_metadata (id SERIAL NOT NULL, key VARCHAR(64) NOT NULL, value VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SYSTEM_METADATA_KEY ON system_metadata (key)');
        $this->addSql("INSERT INTO system_metadata (key, value, created_at) VALUES ('app.name', 'Tittawin Management System', NOW())");
        $this->addSql("INSERT INTO system_metadata (key, value, created_at) VALUES ('app.version', '0.1.0', NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE system_metadata');
    }
}
