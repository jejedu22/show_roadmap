<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112090000 extends AbstractMigration
{
    /** Sites proposés au départ pour le lien d'un trajet ; modifiables dans Administration → Listes. */
    public const LINKS = [
        'SNCF Connect' => 'https://www.sncf-connect.com/',
        'Trainline' => 'https://www.thetrainline.com/fr',
        'OUIGO' => 'https://www.ouigo.com/',
        'Air France' => 'https://wwws.airfrance.fr/',
        'BlaBlaCar' => 'https://www.blablacar.fr/',
        'FlixBus' => 'https://www.flixbus.fr/',
    ];

    public function getDescription(): string
    {
        return 'Liste paramétrée des sites de réservation (lien des trajets)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE list_item ADD url VARCHAR(500) DEFAULT '' NOT NULL");
        $i = 0;
        foreach (self::LINKS as $label => $url) {
            $this->addSql('INSERT IGNORE INTO list_item (kind, label, position, with_class, url) VALUES (?, ?, ?, 0, ?)', ['link', $label, ++$i, $url]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM list_item WHERE kind = 'link'");
        $this->addSql('ALTER TABLE list_item DROP url');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
