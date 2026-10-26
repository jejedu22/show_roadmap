<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026073702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE company (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, notes LONGTEXT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE day (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, night SMALLINT NOT NULL, meals SMALLINT NOT NULL, breakfast SMALLINT NOT NULL, participation_id INT NOT NULL, UNIQUE INDEX UNIQ_E5A029906ACE3B73E5A02990 (participation_id, day), INDEX IDX_E5A029906ACE3B73 (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE event (id INT AUTO_INCREMENT NOT NULL, venue VARCHAR(255) NOT NULL, city VARCHAR(120) NOT NULL, address VARCHAR(255) NOT NULL, venue_contact LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, first_date DATE NOT NULL, last_date DATE NOT NULL, tech_arrival DATE DEFAULT NULL, artistic_arrival DATE DEFAULT NULL, departure DATE DEFAULT NULL, performances INT NOT NULL, notes LONGTEXT NOT NULL, meal_rate NUMERIC(10, 2) NOT NULL, breakfast_rate NUMERIC(10, 2) NOT NULL, night_rate NUMERIC(10, 2) NOT NULL, truck_rate NUMERIC(10, 2) NOT NULL, vat_rate NUMERIC(5, 2) NOT NULL, fee NUMERIC(10, 2) NOT NULL, lighting NUMERIC(10, 2) NOT NULL, posters NUMERIC(10, 2) NOT NULL, show_id INT NOT NULL, INDEX IDX_3BAE0AA75D6C884E (first_date), INDEX IDX_3BAE0AA7D0C1FC64 (show_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE extra_cost (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, amount NUMERIC(10, 2) NOT NULL, kind VARCHAR(20) NOT NULL, event_id INT NOT NULL, INDEX IDX_8C5B57E271F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leg (id INT AUTO_INCREMENT NOT NULL, direction VARCHAR(10) NOT NULL, day DATE NOT NULL, origin VARCHAR(120) NOT NULL, destination VARCHAR(120) NOT NULL, dep_time VARCHAR(20) NOT NULL, arr_time VARCHAR(20) NOT NULL, mode VARCHAR(60) NOT NULL, reference VARCHAR(60) NOT NULL, price NUMERIC(10, 2) NOT NULL, booked_on DATE DEFAULT NULL, note VARCHAR(255) NOT NULL, participation_id INT NOT NULL, INDEX IDX_75D0804F6ACE3B73 (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lodging (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) NOT NULL, phone VARCHAR(60) NOT NULL, details LONGTEXT NOT NULL, event_id INT NOT NULL, INDEX IDX_8D35182A71F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE participation (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(120) NOT NULL, room_type VARCHAR(40) NOT NULL, departure_city VARCHAR(120) NOT NULL, note VARCHAR(255) NOT NULL, event_id INT NOT NULL, person_id INT NOT NULL, lodging_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_AB55E24F71F7E88B217BBB47 (event_id, person_id), INDEX IDX_AB55E24F71F7E88B (event_id), INDEX IDX_AB55E24F217BBB47 (person_id), INDEX IDX_AB55E24F87335AF1 (lodging_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE person (id INT AUTO_INCREMENT NOT NULL, last_name VARCHAR(120) NOT NULL, first_name VARCHAR(120) NOT NULL, function VARCHAR(120) NOT NULL, phone VARCHAR(60) NOT NULL, email VARCHAR(255) NOT NULL, diet VARCHAR(255) NOT NULL, home_city VARCHAR(120) NOT NULL, company_id INT NOT NULL, INDEX IDX_34DCD176979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roadmap_section (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, position INT NOT NULL, event_id INT NOT NULL, INDEX IDX_32AE34F671F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE schedule_item (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, time VARCHAR(20) NOT NULL, label VARCHAR(255) NOT NULL, event_id INT NOT NULL, INDEX IDX_FF53054571F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shows (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, company_id INT NOT NULL, INDEX IDX_6C3BF144979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE truck_leg (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, km NUMERIC(10, 1) NOT NULL, event_id INT NOT NULL, INDEX IDX_621E921771F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, is_admin TINYINT NOT NULL, is_active TINYINT NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_company (user_id INT NOT NULL, company_id INT NOT NULL, INDEX IDX_17B21745A76ED395 (user_id), INDEX IDX_17B21745979B1AD6 (company_id), PRIMARY KEY (user_id, company_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE day ADD CONSTRAINT FK_E5A029906ACE3B73 FOREIGN KEY (participation_id) REFERENCES participation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA7D0C1FC64 FOREIGN KEY (show_id) REFERENCES shows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE extra_cost ADD CONSTRAINT FK_8C5B57E271F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leg ADD CONSTRAINT FK_75D0804F6ACE3B73 FOREIGN KEY (participation_id) REFERENCES participation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lodging ADD CONSTRAINT FK_8D35182A71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation ADD CONSTRAINT FK_AB55E24F87335AF1 FOREIGN KEY (lodging_id) REFERENCES lodging (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD176979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE roadmap_section ADD CONSTRAINT FK_32AE34F671F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE schedule_item ADD CONSTRAINT FK_FF53054571F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE shows ADD CONSTRAINT FK_6C3BF144979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE truck_leg ADD CONSTRAINT FK_621E921771F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_company ADD CONSTRAINT FK_17B21745A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_company ADD CONSTRAINT FK_17B21745979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE day DROP FOREIGN KEY FK_E5A029906ACE3B73');
        $this->addSql('ALTER TABLE event DROP FOREIGN KEY FK_3BAE0AA7D0C1FC64');
        $this->addSql('ALTER TABLE extra_cost DROP FOREIGN KEY FK_8C5B57E271F7E88B');
        $this->addSql('ALTER TABLE leg DROP FOREIGN KEY FK_75D0804F6ACE3B73');
        $this->addSql('ALTER TABLE lodging DROP FOREIGN KEY FK_8D35182A71F7E88B');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F71F7E88B');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F217BBB47');
        $this->addSql('ALTER TABLE participation DROP FOREIGN KEY FK_AB55E24F87335AF1');
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_34DCD176979B1AD6');
        $this->addSql('ALTER TABLE roadmap_section DROP FOREIGN KEY FK_32AE34F671F7E88B');
        $this->addSql('ALTER TABLE schedule_item DROP FOREIGN KEY FK_FF53054571F7E88B');
        $this->addSql('ALTER TABLE shows DROP FOREIGN KEY FK_6C3BF144979B1AD6');
        $this->addSql('ALTER TABLE truck_leg DROP FOREIGN KEY FK_621E921771F7E88B');
        $this->addSql('ALTER TABLE user_company DROP FOREIGN KEY FK_17B21745A76ED395');
        $this->addSql('ALTER TABLE user_company DROP FOREIGN KEY FK_17B21745979B1AD6');
        $this->addSql('DROP TABLE company');
        $this->addSql('DROP TABLE day');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE extra_cost');
        $this->addSql('DROP TABLE leg');
        $this->addSql('DROP TABLE lodging');
        $this->addSql('DROP TABLE participation');
        $this->addSql('DROP TABLE person');
        $this->addSql('DROP TABLE roadmap_section');
        $this->addSql('DROP TABLE schedule_item');
        $this->addSql('DROP TABLE shows');
        $this->addSql('DROP TABLE truck_leg');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE user_company');
    }
}
