<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Équipe habituelle des spectacles (proposée à la création d\'une date)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE show_default_person (show_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_BED52356D0C1FC64 (show_id), INDEX IDX_BED52356217BBB47 (person_id), PRIMARY KEY (show_id, person_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE show_default_person ADD CONSTRAINT FK_BED52356D0C1FC64 FOREIGN KEY (show_id) REFERENCES shows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE show_default_person ADD CONSTRAINT FK_BED52356217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE show_default_person');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
