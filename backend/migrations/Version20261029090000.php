<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Appareils de confiance (« rester connecté »)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trusted_device (id INT AUTO_INCREMENT NOT NULL, series VARCHAR(88) NOT NULL, token_hash VARCHAR(255) NOT NULL, previous_hash VARCHAR(255) DEFAULT NULL, rotated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_used DATETIME NOT NULL, label VARCHAR(120) NOT NULL, ip VARCHAR(45) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_F37E8F7B3A10012D (series), INDEX IDX_F37E8F7BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE trusted_device ADD CONSTRAINT FK_F37E8F7BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trusted_device');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
