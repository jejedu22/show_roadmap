<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression des lignes « Location lumière » et « Affiches » du devis.
 * Les montants déjà saisis deviennent des frais supplémentaires : les totaux ne changent pas.
 */
final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devis : location lumière et affiches deviennent des frais supplémentaires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO extra_cost (event_id, label, amount, kind) SELECT id, 'Location lumière', lighting, 'other' FROM event WHERE lighting <> 0");
        $this->addSql("INSERT INTO extra_cost (event_id, label, amount, kind) SELECT id, 'Affiches', posters, 'other' FROM event WHERE posters <> 0");
        $this->addSql('ALTER TABLE event DROP lighting, DROP posters');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE event ADD lighting NUMERIC(10, 2) DEFAULT '0.00' NOT NULL, ADD posters NUMERIC(10, 2) DEFAULT '0.00' NOT NULL");
    }
}
