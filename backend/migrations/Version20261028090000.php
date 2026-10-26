<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028090000 extends AbstractMigration
{
    /** Valeurs proposées au départ ; les fonctions déjà saisies dans l'annuaire et les équipes s'y ajoutent. */
    public const FUNCTIONS = ['Chorégraphe', 'Metteur en scène', 'Metteuse en scène', 'Danseur', 'Danseuse', 'Comédien', 'Comédienne',
        'Musicien', 'Musicienne', 'Régisseur général', 'Régisseuse générale', 'Régisseur lumière', 'Régisseuse lumière', 'Régisseur son',
        'Régisseuse son', 'Régisseur vidéo', 'Régisseuse vidéo', 'Régisseur plateau', 'Régisseuse plateau', 'Administrateur', 'Administratrice',
        'Admin tournée', 'Chargé de production', 'Chargée de production', 'Chargé de diffusion', 'Chargée de diffusion'];
    public const SCHEDULE = ['Déchargement', 'Montage', 'Montage plateau', 'Réglage lumière', 'Réglage son', 'Balance son', 'Conduite',
        'Conduite lumière', 'Inter-service son', 'RDV technique', 'Échauffement danseurs', 'Plateau pour les danseurs', 'Répétition', 'Filage',
        'Raccords', 'Générale', 'Mise en loge', 'Représentation', 'Nettoyage plateau', 'Nettoyage plateau + mise', 'Démontage', 'Chargement', 'Pause', 'Pause repas',
        'Petit-déjeuner', 'Déjeuner', 'Dîner', 'Atelier', 'Rencontre avec le public', 'Transfert', 'Départ', 'Arrivée'];

    public function getDescription(): string
    {
        return 'Listes paramétrées (fonctions, intitulés de planning) ; précision libre des lignes de planning';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE list_item (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, label VARCHAR(120) NOT NULL, position INT NOT NULL, UNIQUE INDEX list_item_label (kind, label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql("ALTER TABLE schedule_item ADD detail VARCHAR(255) DEFAULT '' NOT NULL");
        foreach (['function' => self::FUNCTIONS, 'schedule' => self::SCHEDULE] as $kind => $labels) {
            foreach ($labels as $i => $label) {
                $this->addSql('INSERT IGNORE INTO list_item (kind, label, position) VALUES (?, ?, ?)', [$kind, $label, $i + 1]);
            }
        }
        // Fonctions déjà utilisées : gardées dans la liste (doublons à la casse ou aux accents près ignorés par l'index)
        foreach (['SELECT DISTINCT LEFT(TRIM(function), 120) AS v FROM person', 'SELECT DISTINCT LEFT(TRIM(role), 120) AS v FROM participation'] as $source) {
            $this->addSql("INSERT IGNORE INTO list_item (kind, label, position) SELECT 'function', v, 1000 FROM ($source) t WHERE v <> ''");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE list_item');
        $this->addSql('ALTER TABLE schedule_item DROP detail');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
