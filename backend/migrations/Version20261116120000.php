<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Détaxes : contingent par date et par représentation, type de billet (invitation ou détaxe) de chaque invité';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invitation ADD ticket VARCHAR(10) DEFAULT 'invitation' NOT NULL");
        $this->addSql('ALTER TABLE event ADD detaxe_quota INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE schedule_item ADD detaxe_quota INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP ticket');
        $this->addSql('ALTER TABLE event DROP detaxe_quota');
        $this->addSql('ALTER TABLE schedule_item DROP detaxe_quota');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
