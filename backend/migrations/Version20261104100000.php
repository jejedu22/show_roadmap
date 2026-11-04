<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site web des hébergements (bouton dans la feuille de route)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE lodging ADD website VARCHAR(1000) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lodging DROP website');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
