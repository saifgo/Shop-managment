<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track storage key and MIME type for uploaded product pictures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_media ADD storage_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_media ADD mime_type VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_media DROP storage_key');
        $this->addSql('ALTER TABLE product_media DROP mime_type');
    }
}
