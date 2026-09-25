<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commercial documents: public share token and invoice stamp duty (timbre fiscal)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commercial_documents ADD share_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE commercial_documents ADD stamp_duty_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DOCUMENT_SHARE_TOKEN ON commercial_documents (share_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_DOCUMENT_SHARE_TOKEN');
        $this->addSql('ALTER TABLE commercial_documents DROP stamp_duty_amount');
        $this->addSql('ALTER TABLE commercial_documents DROP share_token');
    }
}
