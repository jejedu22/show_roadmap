<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liens de partage avec une date d\'expiration (30 jours après la fin de la date, au moins 30 jours à partir d\'aujourd\'hui pour les liens existants)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD share_expires_on DATE DEFAULT NULL, ADD cost_share_expires_on DATE DEFAULT NULL');
        $end = 'GREATEST(COALESCE(departure, last_date), last_date, CURDATE()) + INTERVAL 30 DAY';
        $this->addSql("UPDATE event SET share_expires_on = $end WHERE share_token IS NOT NULL");
        $this->addSql("UPDATE event SET cost_share_expires_on = $end WHERE cost_share_token IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP share_expires_on, DROP cost_share_expires_on');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
