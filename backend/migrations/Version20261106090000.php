<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réglages globaux de l\'application (thème de couleurs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(60) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
