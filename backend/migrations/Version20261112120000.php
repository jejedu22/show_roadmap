<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chambres partagées (double, twin, appartement) : rooming-list et nuitées du devis par chambre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation ADD room_group INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation DROP room_group');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
