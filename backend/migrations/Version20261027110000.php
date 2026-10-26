<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Groupes et droits à la place de l'accès « par compagnie ».
 * Reprise : chaque compagnie reçoit un groupe « Équipe <compagnie> » avec tous les droits sur elle, et ceux qui y avaient
 * accès en deviennent membres (aucun accès gagné ni perdu). Trois groupes modèles, sans membres, sont ajoutés.
 */
final class Version20261027110000 extends AbstractMigration
{
    private const COMPANY_PERMS = '["company.view","events.edit","travel.edit","people.view","people.edit","finance.view","finance.edit","shows.edit","share.manage","company.manage"]';

    public function getDescription(): string
    {
        return 'Utilisateurs : groupes, droits, journal d\'audit, liens d\'invitation';
    }

    public function up(Schema $schema): void
    {
        $opts = 'DEFAULT CHARACTER SET utf8mb4';
        $this->addSql("CREATE TABLE app_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, permissions JSON NOT NULL, all_companies TINYINT NOT NULL, UNIQUE INDEX UNIQ_BB13C9085E237E06 (name), PRIMARY KEY (id)) $opts");
        $this->addSql("CREATE TABLE group_company (group_id INT NOT NULL, company_id INT NOT NULL, INDEX IDX_C9BF5D43FE54D947 (group_id), INDEX IDX_C9BF5D43979B1AD6 (company_id), PRIMARY KEY (group_id, company_id)) $opts");
        $this->addSql("CREATE TABLE group_user (group_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_A4C98D39FE54D947 (group_id), INDEX IDX_A4C98D39A76ED395 (user_id), PRIMARY KEY (group_id, user_id)) $opts");
        $this->addSql("CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, at DATETIME NOT NULL, actor VARCHAR(180) NOT NULL, action VARCHAR(60) NOT NULL, target VARCHAR(255) NOT NULL, details JSON NOT NULL, ip VARCHAR(45) NOT NULL, INDEX IDX_F6E1C0F56A57FD3C (at), PRIMARY KEY (id)) $opts");
        $this->addSql("CREATE TABLE user_token (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_BDF55A63D1B862B8 (hash), INDEX IDX_BDF55A63A76ED395 (user_id), PRIMARY KEY (id)) $opts");
        $this->addSql('ALTER TABLE group_company ADD CONSTRAINT FK_C9BF5D43FE54D947 FOREIGN KEY (group_id) REFERENCES app_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_company ADD CONSTRAINT FK_C9BF5D43979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_user ADD CONSTRAINT FK_A4C98D39FE54D947 FOREIGN KEY (group_id) REFERENCES app_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_user ADD CONSTRAINT FK_A4C98D39A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_token ADD CONSTRAINT FK_BDF55A63A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE users ADD created_at DATETIME DEFAULT NULL, ADD last_login_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE users SET created_at = NOW()');
        $this->addSql('ALTER TABLE users MODIFY created_at DATETIME NOT NULL');

        // Reprise des accès existants : un groupe « Équipe » par compagnie (numéro ajouté si deux compagnies ont le même nom)
        $name = "LEFT(CONCAT('Équipe ', c.name, IF((SELECT COUNT(*) FROM company c2 WHERE c2.name = c.name) > 1, CONCAT(' (', c.id, ')'), '')), 120)";
        $this->addSql("INSERT INTO app_group (name, description, permissions, all_companies) SELECT $name, CONCAT('Tous les droits sur ', c.name, '.'), '".self::COMPANY_PERMS."', 0 FROM company c");
        $this->addSql("INSERT INTO group_company (group_id, company_id) SELECT g.id, c.id FROM company c JOIN app_group g ON g.name = $name");
        $this->addSql('INSERT INTO group_user (group_id, user_id) SELECT gc.group_id, uc.user_id FROM user_company uc JOIN group_company gc ON gc.company_id = uc.company_id');
        $this->addSql('DROP TABLE user_company');

        // Groupes modèles (sans membres ni compagnies : à compléter)
        $this->addSql("INSERT IGNORE INTO app_group (name, description, permissions, all_companies) VALUES
            ('Régie et logistique', 'Organise les dates, les voyages et l\\'hébergement, partage la feuille de route ; ne voit pas les montants.', '[\"company.view\",\"events.edit\",\"travel.edit\",\"people.view\",\"share.manage\"]', 0),
            ('Consultation', 'Voit les dates, équipes, hébergements et voyages, sans montants ni coordonnées.', '[\"company.view\"]', 0),
            ('Administration', 'Gère les comptes, les groupes et les compagnies, avec tous les droits sur toutes les compagnies.', '[\"users.manage\",\"groups.manage\",\"companies.create\",\"companies.delete\",\"company.view\",\"events.edit\",\"travel.edit\",\"people.view\",\"people.edit\",\"finance.view\",\"finance.edit\",\"shows.edit\",\"share.manage\",\"company.manage\"]', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_company (user_id INT NOT NULL, company_id INT NOT NULL, INDEX IDX_17B21745979B1AD6 (company_id), INDEX IDX_17B21745A76ED395 (user_id), PRIMARY KEY (user_id, company_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('INSERT IGNORE INTO user_company (user_id, company_id) SELECT gu.user_id, gc.company_id FROM group_user gu JOIN group_company gc ON gc.group_id = gu.group_id');
        $this->addSql('ALTER TABLE user_company ADD CONSTRAINT FK_17B21745979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_company ADD CONSTRAINT FK_17B21745A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        foreach (['user_token', 'audit_log', 'group_user', 'group_company', 'app_group'] as $t) {
            $this->addSql("DROP TABLE $t");
        }
        $this->addSql('ALTER TABLE users DROP created_at, DROP last_login_at');
    }
}
