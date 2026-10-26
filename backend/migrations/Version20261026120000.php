<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Horaires théoriques SNCF (GTFS open data), rechargés chaque nuit par app:gtfs-import. */
final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables des horaires SNCF (GTFS)';
    }

    public function up(Schema $schema): void
    {
        $opts = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB';
        $this->addSql("CREATE TABLE gtfs_stop (id VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id), INDEX gtfs_stop_name (name)) $opts");
        $this->addSql("CREATE TABLE gtfs_trip (id INT NOT NULL, service_id VARCHAR(32) NOT NULL, number VARCHAR(32) NOT NULL, mode VARCHAR(64) NOT NULL, PRIMARY KEY (id)) $opts");
        $this->addSql("CREATE TABLE gtfs_stop_time (trip_id INT NOT NULL, seq SMALLINT NOT NULL, stop_id VARCHAR(64) NOT NULL, arr_min SMALLINT NOT NULL, dep_min SMALLINT NOT NULL, can_board TINYINT(1) NOT NULL, can_alight TINYINT(1) NOT NULL, PRIMARY KEY (trip_id, seq), INDEX gtfs_stop_time_stop (stop_id, dep_min)) $opts");
        $this->addSql("CREATE TABLE gtfs_service_date (day DATE NOT NULL, service_id VARCHAR(32) NOT NULL, PRIMARY KEY (day, service_id)) $opts");
        $this->addSql("CREATE TABLE gtfs_meta (name VARCHAR(32) NOT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY (name)) $opts");
    }

    public function down(Schema $schema): void
    {
        foreach (['gtfs_stop', 'gtfs_trip', 'gtfs_stop_time', 'gtfs_service_date', 'gtfs_meta'] as $t) {
            $this->addSql("DROP TABLE $t");
        }
    }
}
