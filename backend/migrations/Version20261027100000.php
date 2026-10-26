<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dates : lien de partage de la feuille de route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD share_token VARCHAR(64) DEFAULT NULL, ADD shared_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3BAE0AA7D6594DD6 ON event (share_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_3BAE0AA7D6594DD6 ON event');
        $this->addSql('ALTER TABLE event DROP share_token, DROP shared_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
