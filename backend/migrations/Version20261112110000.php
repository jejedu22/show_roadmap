<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Budget transport du devis (ligne en plus du transport réel de l\'équipe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE event ADD transport_budget NUMERIC(10, 2) DEFAULT '0.00' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP transport_budget');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
