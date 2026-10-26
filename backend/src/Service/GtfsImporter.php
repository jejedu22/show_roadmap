<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge les horaires théoriques SNCF (GTFS open data) dans les tables gtfs_*.
 *
 * L'import se fait dans des tables *_new puis bascule d'un coup (RENAME TABLE) :
 * la recherche reste disponible pendant le chargement et un fichier invalide ne casse rien.
 */
class GtfsImporter
{
    private const TABLES = ['gtfs_stop', 'gtfs_trip', 'gtfs_stop_time', 'gtfs_service_date'];
    private const BATCH = 1000;

    public function __construct(
        private Connection $db,
        // En dev, Symfony garde chaque requête SQL pour le profiler : on le vide pendant l'import (500 000 lignes)
        #[Autowire(service: 'doctrine.debug_data_holder')] private ?DebugDataHolder $debugQueries = null,
    ) {
    }

    /**
     * @return array<string, int|string> statistiques de l'import
     *
     * @throws GtfsImportInProgress si un autre import tourne déjà (démarrage du conteneur, commande manuelle…)
     */
    public function import(string $zipPath): array
    {
        // Verrou MariaDB : un seul import à la fois, quel que soit le processus ou le conteneur
        if (1 !== (int) $this->db->fetchOne("SELECT GET_LOCK('gtfs_import', 0)")) {
            throw new GtfsImportInProgress('Un import des horaires SNCF est déjà en cours.');
        }
        try {
            return $this->importLocked($zipPath);
        } finally {
            $this->db->fetchOne("SELECT RELEASE_LOCK('gtfs_import')");
        }
    }

    private function importLocked(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new \RuntimeException("Archive GTFS illisible : $zipPath");
        }
        foreach (['stops.txt', 'trips.txt', 'stop_times.txt'] as $required) {
            if (false === $zip->locateName($required)) {
                throw new \RuntimeException("Fichier $required absent de l'archive GTFS");
            }
        }

        foreach (self::TABLES as $t) {
            $this->db->executeStatement("DROP TABLE IF EXISTS {$t}_new");
            $this->db->executeStatement("CREATE TABLE {$t}_new LIKE $t");
        }
        try {
            $stats = $this->load($zip);
        } catch (\Throwable $e) {
            foreach (self::TABLES as $t) {
                $this->db->executeStatement("DROP TABLE IF EXISTS {$t}_new");
            }
            throw $e;
        } finally {
            $zip->close();
        }

        // Bascule atomique, puis suppression des anciennes données
        $renames = [];
        foreach (self::TABLES as $t) {
            $this->db->executeStatement("DROP TABLE IF EXISTS {$t}_old");
            $renames[] = "$t TO {$t}_old, {$t}_new TO $t";
        }
        $this->db->executeStatement('RENAME TABLE '.implode(', ', $renames));
        foreach (self::TABLES as $t) {
            $this->db->executeStatement("DROP TABLE {$t}_old");
        }
        $stats['importedAt'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        foreach ($stats as $name => $value) {
            $this->db->executeStatement('REPLACE INTO gtfs_meta (name, value) VALUES (?, ?)', [$name, (string) $value]);
        }

        return $stats;
    }

    public function lastImport(): ?\DateTimeImmutable
    {
        $v = $this->db->fetchOne("SELECT value FROM gtfs_meta WHERE name = 'importedAt'");

        return $v ? new \DateTimeImmutable($v) : null;
    }

    private function load(\ZipArchive $zip): array
    {
        // Arrêts physiques (StopPoint) → gare (StopArea) et mode (« OCETGV INOUI-… » → « TGV INOUI »)
        $areaOf = $modeOf = $areas = [];
        foreach ($this->rows($zip, 'stops.txt') as $r) {
            if ('1' === ($r['location_type'] ?? '')) {
                $areas[$r['stop_id']] = $r['stop_name'];
            } else {
                $areaOf[$r['stop_id']] = $r['parent_station'] ?: $r['stop_id'];
                $areas[$r['stop_id']] ??= $r['stop_name'];
                $modeOf[$r['stop_id']] = self::mode($r['stop_id']);
            }
        }

        // trip_id GTFS → id numérique ; service et numéro rangés à part (identifiants GTFS longs : on limite la mémoire)
        $tripIds = $tripInfo = [];
        foreach ($this->rows($zip, 'trips.txt') as $r) {
            $id = count($tripIds) + 1;
            $tripIds[$r['trip_id']] = $id;
            $tripInfo[$id] = $r['service_id']."\t".($r['trip_headsign'] ?? '');
        }

        $tripMode = $usedAreas = [];
        $batch = [];
        $stopTimes = 0;
        foreach ($this->rows($zip, 'stop_times.txt') as $r) {
            $trip = $tripIds[$r['trip_id']] ?? null;
            $area = $areaOf[$r['stop_id']] ?? null;
            if (!$trip || !$area) {
                continue;
            }
            $tripMode[$trip] ??= $modeOf[$r['stop_id']] ?? 'Train';
            $usedAreas[$area] = true;
            $batch[] = [$trip, (int) $r['stop_sequence'], $area, self::minutes($r['arrival_time'] ?: $r['departure_time']),
                self::minutes($r['departure_time'] ?: $r['arrival_time']), '1' === ($r['pickup_type'] ?? '0') ? 0 : 1,
                '1' === ($r['drop_off_type'] ?? '0') ? 0 : 1];
            if (count($batch) >= self::BATCH) {
                $stopTimes += $this->insert('gtfs_stop_time_new', ['trip_id', 'seq', 'stop_id', 'arr_min', 'dep_min', 'can_board', 'can_alight'], $batch);
                $batch = [];
            }
        }
        $stopTimes += $this->insert('gtfs_stop_time_new', ['trip_id', 'seq', 'stop_id', 'arr_min', 'dep_min', 'can_board', 'can_alight'], $batch);

        unset($tripIds);
        $rows = [];
        $tripCount = 0;
        foreach ($tripMode as $id => $mode) {
            [$service, $number] = explode("\t", $tripInfo[$id]);
            $rows[] = [$id, $service, $number, $mode];
            if (count($rows) >= self::BATCH) {
                $tripCount += $this->insert('gtfs_trip_new', ['id', 'service_id', 'number', 'mode'], $rows);
                $rows = [];
            }
        }
        $tripCount += $this->insert('gtfs_trip_new', ['id', 'service_id', 'number', 'mode'], $rows);
        unset($tripInfo, $tripMode);

        $rows = [];
        foreach (array_keys($usedAreas) as $id) {
            $rows[] = [$id, $areas[$id] ?? $id];
        }
        $stopCount = $this->insertAll('gtfs_stop_new', ['id', 'name'], $rows);

        // Jours de circulation : calendar.txt (jours de semaine sur une période) puis exceptions de calendar_dates.txt
        $days = [];
        if (false !== $zip->locateName('calendar.txt')) {
            $names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($this->rows($zip, 'calendar.txt') as $r) {
                $end = self::date($r['end_date']);
                for ($d = self::date($r['start_date']); $d <= $end; $d = $d->modify('+1 day')) {
                    if ('1' === $r[$names[(int) $d->format('N') - 1]]) {
                        $days[$r['service_id'].'|'.$d->format('Y-m-d')] = true;
                    }
                }
            }
        }
        if (false !== $zip->locateName('calendar_dates.txt')) {
            foreach ($this->rows($zip, 'calendar_dates.txt') as $r) {
                $key = $r['service_id'].'|'.self::date($r['date'])->format('Y-m-d');
                if ('2' === $r['exception_type']) {
                    unset($days[$key]);
                } else {
                    $days[$key] = true;
                }
            }
        }
        $rows = [];
        $first = $last = null;
        foreach (array_keys($days) as $key) {
            [$service, $day] = explode('|', $key);
            $rows[] = [$day, $service];
            $first = null === $first || $day < $first ? $day : $first;
            $last = null === $last || $day > $last ? $day : $last;
            if (count($rows) >= self::BATCH) {
                $this->insert('gtfs_service_date_new', ['day', 'service_id'], $rows);
                $rows = [];
            }
        }
        $this->insert('gtfs_service_date_new', ['day', 'service_id'], $rows);

        if ($stopTimes < 1) {
            throw new \RuntimeException('Aucun horaire trouvé dans l\'archive GTFS');
        }

        return ['stations' => $stopCount, 'trips' => $tripCount, 'stopTimes' => $stopTimes, 'firstDay' => $first ?? '', 'lastDay' => $last ?? ''];
    }

    /** Lit un fichier CSV de l'archive ligne à ligne, en tableaux associatifs. */
    private function rows(\ZipArchive $zip, string $name): \Generator
    {
        $stream = $zip->getStream($name) ?: throw new \RuntimeException("Lecture impossible : $name");
        try {
            $header = fgetcsv($stream, escape: '');
            if (!$header) {
                return;
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // BOM
            $header = array_map('trim', $header);
            while (false !== ($line = fgetcsv($stream, escape: ''))) {
                if ([null] === $line) {
                    continue;
                }
                yield array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param list<list<mixed>> $rows */
    private function insertAll(string $table, array $columns, array $rows): int
    {
        $n = 0;
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $n += $this->insert($table, $columns, $chunk);
        }

        return $n;
    }

    private function insert(string $table, array $columns, array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $this->debugQueries?->reset();
        $row = '('.implode(',', array_fill(0, count($columns), '?')).')';
        $this->db->executeStatement(
            "INSERT INTO $table (".implode(',', $columns).') VALUES '.implode(',', array_fill(0, count($rows), $row)),
            array_merge(...$rows),
        );

        return count($rows);
    }

    /** « 25:10:00 » → 1510 (les trains de nuit dépassent 24 h). */
    private static function minutes(string $hms): int
    {
        $p = explode(':', $hms);

        return (int) $p[0] * 60 + (int) ($p[1] ?? 0);
    }

    private static function date(string $yyyymmdd): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Ymd', $yyyymmdd) ?: throw new \RuntimeException("Date GTFS invalide : $yyyymmdd");
    }

    /** « StopPoint:OCETGV INOUI-87686006 » → « TGV INOUI », « StopPoint:OCETrain TER-… » → « TER ». */
    private static function mode(string $stopId): string
    {
        if (!preg_match('/^StopPoint:OCE(.+)-[^-]+$/', $stopId, $m)) {
            return 'Train';
        }

        return match ($m[1]) {
            'Train TER' => 'TER',
            'Car TER' => 'Car TER',
            default => $m[1],
        };
    }
}
