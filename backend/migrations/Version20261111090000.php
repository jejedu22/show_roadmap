<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111090000 extends AbstractMigration
{
    /** Modes proposés au départ avec une classe (1re / 2nde) ; modifiable dans Administration → Listes. */
    public const CLASS_MODES = ['Train', 'TER', 'TGV INOUI', 'Intercités', 'ICE'];

    public function getDescription(): string
    {
        return 'Classe (1re / 2nde) des trajets, pour les modes de transport qui en ont une';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE list_item ADD with_class TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE leg ADD travel_class VARCHAR(1) DEFAULT '' NOT NULL");
        foreach (self::CLASS_MODES as $label) {
            $this->addSql("UPDATE list_item SET with_class = 1 WHERE kind = 'mode' AND label = ?", [$label]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE list_item DROP with_class');
        $this->addSql('ALTER TABLE leg DROP travel_class');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
