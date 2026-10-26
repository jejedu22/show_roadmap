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
     * Charge une ou plusieurs archives : la première est le fichier national (TGV, Intercités, TER) ; les suivantes
     * (Transilien et RER) complètent la desserte. Leurs gares sont rapprochées des gares nationales voisines
     * (« Saint-Lazare » → Paris Saint-Lazare) pour permettre les correspondances.
     *
     * @param string|list<string> $zipPaths
     *
     * @return array<string, int|string> statistiques de l'import
     *
     * @throws GtfsImportInProgress si un autre import tourne déjà (démarrage du conteneur, commande manuelle…)
     */
    public function import(string|array $zipPaths): array
    {
        // Verrou MariaDB : un seul import à la fois, quel que soit le processus ou le conteneur
        if (1 !== (int) $this->db->fetchOne("SELECT GET_LOCK('gtfs_import', 0)")) {
            throw new GtfsImportInProgress('Un import des horaires SNCF est déjà en cours.');
        }
        try {
            return $this->importLocked((array) $zipPaths);
        } finally {
            $this->db->fetchOne("SELECT RELEASE_LOCK('gtfs_import')");
        }
    }

    private function importLocked(array $zipPaths): array
    {
        $zips = [];
        foreach ($zipPaths as $path) {
            $zip = new \ZipArchive();
            if (true !== $zip->open($path)) {
                throw new \RuntimeException("Archive GTFS illisible : $path");
            }
            foreach (['stops.txt', 'trips.txt', 'stop_times.txt'] as $required) {
                if (false === $zip->locateName($required)) {
                    throw new \RuntimeException("Fichier $required absent de l'archive GTFS");
                }
            }
            $zips[] = $zip;
        }

        foreach (self::TABLES as $t) {
            $this->db->executeStatement("DROP TABLE IF EXISTS {$t}_new");
            $this->db->executeStatement("CREATE TABLE {$t}_new LIKE $t");
        }
        try {
            $state = ['nextTrip' => 1, 'names' => [], 'coords' => [], 'used' => [], 'days' => [], 'trips' => 0, 'stopTimes' => 0];
            foreach ($zips as $i => $zip) {
                $this->load($zip, $i, $state);
            }
            $stats = $this->finish($state);
        } catch (\Throwable $e) {
            foreach (self::TABLES as $t) {
                $this->db->executeStatement("DROP TABLE IF EXISTS {$t}_new");
            }
            throw $e;
        } finally {
            foreach ($zips as $zip) {
                $zip->close();
            }
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

    /** Charge une archive dans les tables *_new ; $index 0 = fichier national, sinon fichier complémentaire. */
    private function load(\ZipArchive $zip, int $index, array &$state): void
    {
        $extra = $index > 0;
        // Gares (StopArea) et arrêts physiques (StopPoint) ; mode déduit de l'arrêt (« OCETGV INOUI-… » → « TGV INOUI »)
        $areaOf = $modeOf = $names = $coords = [];
        foreach ($this->rows($zip, 'stops.txt') as $r) {
            if ('1' === ($r['location_type'] ?? '')) {
                $names[$r['stop_id']] = $r['stop_name'];
                if (is_numeric($r['stop_lat'] ?? null) && is_numeric($r['stop_lon'] ?? null)) {
                    $coords[$r['stop_id']] = [(float) $r['stop_lat'], (float) $r['stop_lon']];
                }
            } elseif (in_array($r['location_type'] ?? '', ['', '0'], true)) {
                $areaOf[$r['stop_id']] = $r['parent_station'] ?: $r['stop_id'];
                $names[$r['stop_id']] ??= $r['stop_name'];
                $modeOf[$r['stop_id']] = self::mode($r['stop_id']);
            }
        }
        if ($extra) {
            // Gares complémentaires : rattachées à la gare nationale voisine, sinon gardées sous leur propre nom.
            $same = [];
            foreach ($names as $id => $name) {
                $same[$id] = isset($coords[$id]) ? $this->nationalTwin($name, $coords[$id], $state) : null;
            }
            foreach ($areaOf as $stop => $area) {
                $areaOf[$stop] = $same[$area] ?? $area;
            }
            foreach ($names as $id => $name) {
                $state['names'][$id] ??= $name;
            }
        } else {
            $state['names'] += $names;
            $state['coords'] += $coords;
        }

        // Lignes (fichiers complémentaires) : « RER C », « Transilien J » ; les TER y sont en double du fichier national.
        $routeMode = [];
        if ($extra && false !== $zip->locateName('routes.txt')) {
            $agencies = [];
            if (false !== $zip->locateName('agency.txt')) {
                foreach ($this->rows($zip, 'agency.txt') as $r) {
                    $agencies[$r['agency_id'] ?? ''] = $r['agency_name'] ?? '';
                }
            }
            foreach ($this->rows($zip, 'routes.txt') as $r) {
                $agency = $agencies[$r['agency_id'] ?? ''] ?? '';
                $routeMode[$r['route_id']] = 'TER' === strtoupper($r['route_short_name'] ?? '') || 'TER' === strtoupper($agency) ? null
                    : trim(('3' === ($r['route_type'] ?? '') ? 'Car ' : '').$agency.' '.($r['route_short_name'] ?? ''));
            }
        }

        // trip_id GTFS → id numérique ; service et numéro rangés à part (identifiants GTFS longs : on limite la mémoire)
        $prefix = $extra ? $index.':' : '';
        $tripIds = $tripInfo = $tripRouteMode = [];
        foreach ($this->rows($zip, 'trips.txt') as $r) {
            if ($extra && array_key_exists($r['route_id'] ?? '', $routeMode) && null === $routeMode[$r['route_id']]) {
                continue;
            }
            $id = $state['nextTrip']++;
            $tripIds[$r['trip_id']] = $id;
            // Numéro du train : trip_headsign dans le fichier national, trip_short_name (« 141572-141573 ») ailleurs
            $number = $extra ? explode('-', $r['trip_short_name'] ?? '')[0] : ($r['trip_headsign'] ?? '');
            $tripInfo[$id] = $prefix.$r['service_id']."\t".mb_substr($number, 0, 32);
            if ($extra && isset($routeMode[$r['route_id'] ?? ''])) {
                $tripRouteMode[$id] = $routeMode[$r['route_id']];
            }
        }

        $tripMode = [];
        $batch = [];
        foreach ($this->rows($zip, 'stop_times.txt') as $r) {
            $trip = $tripIds[$r['trip_id']] ?? null;
            $area = $areaOf[$r['stop_id']] ?? null;
            if (!$trip || !$area) {
                continue;
            }
            $tripMode[$trip] ??= $tripRouteMode[$trip] ?? $modeOf[$r['stop_id']] ?? 'Train';
            $state['used'][$area] = true;
            $batch[] = [$trip, (int) $r['stop_sequence'], $area, self::minutes($r['arrival_time'] ?: $r['departure_time']),
                self::minutes($r['departure_time'] ?: $r['arrival_time']), '1' === ($r['pickup_type'] ?? '0') ? 0 : 1,
                '1' === ($r['drop_off_type'] ?? '0') ? 0 : 1];
            if (count($batch) >= self::BATCH) {
                $state['stopTimes'] += $this->insert('gtfs_stop_time_new', ['trip_id', 'seq', 'stop_id', 'arr_min', 'dep_min', 'can_board', 'can_alight'], $batch);
                $batch = [];
            }
        }
        $state['stopTimes'] += $this->insert('gtfs_stop_time_new', ['trip_id', 'seq', 'stop_id', 'arr_min', 'dep_min', 'can_board', 'can_alight'], $batch);

        unset($tripIds);
        $rows = [];
        foreach ($tripMode as $id => $mode) {
            [$service, $number] = explode("\t", $tripInfo[$id]);
            $rows[] = [$id, $service, $number, mb_substr($mode, 0, 64)];
            if (count($rows) >= self::BATCH) {
                $state['trips'] += $this->insert('gtfs_trip_new', ['id', 'service_id', 'number', 'mode'], $rows);
                $rows = [];
            }
        }
        $state['trips'] += $this->insert('gtfs_trip_new', ['id', 'service_id', 'number', 'mode'], $rows);
        unset($tripInfo, $tripMode);

        // Jours de circulation : calendar.txt (jours de semaine sur une période) puis exceptions de calendar_dates.txt
        if (false !== $zip->locateName('calendar.txt')) {
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($this->rows($zip, 'calendar.txt') as $r) {
                $end = self::date($r['end_date']);
                for ($d = self::date($r['start_date']); $d <= $end; $d = $d->modify('+1 day')) {
                    if ('1' === $r[$days[(int) $d->format('N') - 1]]) {
                        $state['days'][$prefix.$r['service_id'].'|'.$d->format('Y-m-d')] = true;
                        $extra && $state['extraLast'] = max($state['extraLast'] ?? '', $d->format('Y-m-d'));
                    }
                }
            }
        }
        if (false !== $zip->locateName('calendar_dates.txt')) {
            foreach ($this->rows($zip, 'calendar_dates.txt') as $r) {
                $key = $prefix.$r['service_id'].'|'.self::date($r['date'])->format('Y-m-d');
                if ('2' === $r['exception_type']) {
                    unset($state['days'][$key]);
                } else {
                    $state['days'][$key] = true;
                    $extra && $state['extraLast'] = max($state['extraLast'] ?? '', self::date($r['date'])->format('Y-m-d'));
                }
            }
        }
    }

    /** Gares utilisées et jours de circulation ; statistiques. */
    private function finish(array $state): array
    {
        $rows = [];
        foreach (array_keys($state['used']) as $id) {
            $rows[] = [$id, $state['names'][$id] ?? $id];
        }
        $stopCount = $this->insertAll('gtfs_stop_new', ['id', 'name'], $rows);

        $rows = [];
        $first = $last = null;
        foreach (array_keys($state['days']) as $key) {
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

        if ($state['stopTimes'] < 1) {
            throw new \RuntimeException('Aucun horaire trouvé dans l\'archive GTFS');
        }

        // Les horaires Transilien et RER sont publiés environ un mois à l'avance seulement
        return ['stations' => $stopCount, 'trips' => $state['trips'], 'stopTimes' => $state['stopTimes'], 'firstDay' => $first ?? '', 'lastDay' => $last ?? '',
            'idfLastDay' => $state['extraLast'] ?? ''];
    }

    /**
     * Gare nationale correspondant à une gare d'un fichier complémentaire : à moins de 120 m, ou à moins de 400 m
     * avec un mot du nom en commun (« Gare de Lyon » ↔ « Paris Gare de Lyon Hall 1 - 2 »).
     */
    private function nationalTwin(string $name, array $coord, array $state): ?string
    {
        $words = fn (string $n) => array_diff(preg_split('/[^a-z0-9]+/', strtolower(preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($n, \Normalizer::FORM_D)))),
            ['', 'paris', 'gare', 'de', 'du', 'des', 'la', 'le', 'les', 'saint', 'st', 'hall', 'rer', '1', '2', '3']);
        $mine = $words($name);
        $best = null;
        $bestDist = 400.0;
        foreach ($state['coords'] as $id => [$lat, $lon]) {
            if (abs($lat - $coord[0]) > 0.004 || abs($lon - $coord[1]) > 0.006) {
                continue;
            }
            $dist = 6371000 * sqrt((deg2rad($lat - $coord[0])) ** 2 + (cos(deg2rad($lat)) * deg2rad($lon - $coord[1])) ** 2);
            if ($dist < $bestDist && ($dist <= 120 || array_intersect($mine, $words($state['names'][$id] ?? '')))) {
                [$best, $bestDist] = [$id, $dist];
            }
        }

        return $best;
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
