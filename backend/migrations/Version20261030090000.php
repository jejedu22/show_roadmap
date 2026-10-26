<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Intégration Orfeo : clé d\'API par compagnie, identifiants Orfeo des spectacles, dates et personnes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE company ADD orfeo_token LONGTEXT DEFAULT NULL, ADD orfeo_organization VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE shows ADD orfeo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD orfeo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE person ADD orfeo_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP orfeo_token, DROP orfeo_organization');
        $this->addSql('ALTER TABLE shows DROP orfeo_id');
        $this->addSql('ALTER TABLE event DROP orfeo_id');
        $this->addSql('ALTER TABLE person DROP orfeo_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
