<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devis signé (numéro, dates, déplacements équipe, repas, nuitées) pour la comparaison avec le réalisé';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD signed_quote_number VARCHAR(40) DEFAULT NULL, ADD signed_quote_date DATE DEFAULT NULL, ADD signed_on DATE DEFAULT NULL, '
            .'ADD signed_team_travel NUMERIC(10, 2) DEFAULT NULL, ADD signed_meals INT DEFAULT NULL, ADD signed_nights INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP signed_quote_number, DROP signed_quote_date, DROP signed_on, DROP signed_team_travel, DROP signed_meals, DROP signed_nights');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
