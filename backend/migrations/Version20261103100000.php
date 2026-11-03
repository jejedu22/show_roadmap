<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103100000 extends AbstractMigration
{
    /** Types proposés au départ. */
    public const EXPENSES = ['Taxi', 'VTC', 'Transports en commun', 'Parking', 'Péage', 'Carburant', 'Location de véhicule',
        'Frais d\'échange', 'Bagage supplémentaire', 'Remboursement de billet', 'Autre'];

    public function getDescription(): string
    {
        return 'Dépenses et remboursements sur les trajets ; liste paramétrée des types de dépense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE travel_expense (id INT AUTO_INCREMENT NOT NULL, participation_id INT NOT NULL, direction VARCHAR(10) NOT NULL, kind VARCHAR(120) NOT NULL, amount NUMERIC(10, 2) NOT NULL, day DATE NOT NULL, comment VARCHAR(255) NOT NULL, INDEX IDX_TRAVEL_EXPENSE_PARTICIPATION (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->addSql('ALTER TABLE travel_expense ADD CONSTRAINT FK_TRAVEL_EXPENSE_PARTICIPATION FOREIGN KEY (participation_id) REFERENCES participation (id) ON DELETE CASCADE');
        foreach (self::EXPENSES as $i => $label) {
            $this->addSql('INSERT IGNORE INTO list_item (kind, label, position) VALUES (?, ?, ?)', ['expense', $label, $i + 1]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE travel_expense');
        $this->addSql("DELETE FROM list_item WHERE kind = 'expense'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
