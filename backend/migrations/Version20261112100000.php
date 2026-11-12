<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entrée par défaut des listes (mode de transport, site de réservation, type de dépense)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE list_item ADD is_default TINYINT(1) DEFAULT 0 NOT NULL');
        // Comme avant : « Train » proposé d'office pour un nouveau trajet
        $this->addSql("UPDATE list_item SET is_default = 1 WHERE kind = 'mode' AND label = 'Train'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE list_item DROP is_default');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
