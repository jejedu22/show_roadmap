<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Thème de couleurs par compagnie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD theme VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP theme');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
