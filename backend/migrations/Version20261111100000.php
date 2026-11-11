<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Logo des compagnies (PDF et interface)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_logo (company_id INT NOT NULL, data LONGBLOB NOT NULL, PRIMARY KEY (company_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE company_logo ADD CONSTRAINT FK_A7E380FD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE company ADD logo_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE company_logo');
        $this->addSql('ALTER TABLE company DROP logo_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
