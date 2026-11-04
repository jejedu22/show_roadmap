<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations (Cie, Pro) et places mises à disposition par le lieu, par représentation ou pour la date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation (id INT AUTO_INCREMENT NOT NULL, event_id INT NOT NULL, performance_id INT DEFAULT NULL, kind VARCHAR(10) NOT NULL, last_name VARCHAR(120) NOT NULL, first_name VARCHAR(120) NOT NULL, structure VARCHAR(255) NOT NULL, places INT NOT NULL, INDEX IDX_F11D61A271F7E88B (event_id), INDEX IDX_F11D61A2B91ADEEE (performance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A271F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2B91ADEEE FOREIGN KEY (performance_id) REFERENCES schedule_item (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE event ADD invitation_quota INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE schedule_item ADD invitation_quota INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invitation');
        $this->addSql('ALTER TABLE event DROP invitation_quota');
        $this->addSql('ALTER TABLE schedule_item DROP invitation_quota');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
