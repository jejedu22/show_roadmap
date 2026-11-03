<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lien de partage de l\'export Excel des coûts d\'une date ; noms Doctrine des index de travel_expense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD cost_share_token VARCHAR(64) DEFAULT NULL, ADD cost_shared_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3BAE0AA7F5F64D95 ON event (cost_share_token)');
        // Noms d'index et de clé de la table travel_expense (migration précédente) alignés sur ceux que génère Doctrine
        $this->addSql('ALTER TABLE travel_expense DROP FOREIGN KEY FK_TRAVEL_EXPENSE_PARTICIPATION');
        $this->addSql('ALTER TABLE travel_expense RENAME INDEX IDX_TRAVEL_EXPENSE_PARTICIPATION TO IDX_EC793AB76ACE3B73');
        $this->addSql('ALTER TABLE travel_expense ADD CONSTRAINT FK_EC793AB76ACE3B73 FOREIGN KEY (participation_id) REFERENCES participation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE travel_expense DROP FOREIGN KEY FK_EC793AB76ACE3B73');
        $this->addSql('ALTER TABLE travel_expense RENAME INDEX IDX_EC793AB76ACE3B73 TO IDX_TRAVEL_EXPENSE_PARTICIPATION');
        $this->addSql('ALTER TABLE travel_expense ADD CONSTRAINT FK_TRAVEL_EXPENSE_PARTICIPATION FOREIGN KEY (participation_id) REFERENCES participation (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX UNIQ_3BAE0AA7F5F64D95 ON event');
        $this->addSql('ALTER TABLE event DROP cost_share_token, DROP cost_shared_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
