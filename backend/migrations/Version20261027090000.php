<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trajets : lien vers la réservation ou le billet';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leg ADD booking_url VARCHAR(1000) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leg DROP booking_url');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
