<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chambres double / twin partagées (rooming-list, nuitées du devis par chambre)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation ADD roommate_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F957252FA FOREIGN KEY (roommate_id) REFERENCES participation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AB55E24F957252FA ON participation (roommate_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F957252FA');
        $this->addSql('DROP INDEX IDX_AB55E24F957252FA ON participation');
        $this->addSql('ALTER TABLE participation DROP roommate_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
