<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103090000 extends AbstractMigration
{
    /** Modes proposés au départ ; les modes déjà saisis sur les trajets s'y ajoutent. */
    public const MODES = ['Train', 'TER', 'TGV INOUI', 'OUIGO', 'Intercités', 'ICE', 'Eurostar', 'Avion', 'Véhicule perso', 'Bus', 'Taxi'];

    public function getDescription(): string
    {
        return 'Liste paramétrée des modes de transport (voyages)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::MODES as $i => $label) {
            $this->addSql('INSERT IGNORE INTO list_item (kind, label, position) VALUES (?, ?, ?)', ['mode', $label, $i + 1]);
        }
        // Modes déjà utilisés : gardés dans la liste (doublons à la casse ou aux accents près ignorés par l'index)
        $this->addSql("INSERT IGNORE INTO list_item (kind, label, position) SELECT 'mode', v, 1000 FROM (SELECT DISTINCT LEFT(TRIM(mode), 120) AS v FROM leg) t WHERE v <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM list_item WHERE kind = 'mode'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
