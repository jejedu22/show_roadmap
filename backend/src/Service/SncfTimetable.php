<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Recherche de trains (directs ou avec une correspondance) dans les horaires théoriques SNCF importés (voir GtfsImporter). */
class SncfTimetable
{
    /** Villes à plusieurs gares : correspondance possible d'une gare à l'autre, avec ce délai minimum (minutes). */
    private const CITY_TRANSFER = ['paris' => 60, 'lyon' => 35, 'lille' => 25];
    /** Délai minimum pour changer de train dans la même gare. */
    private const SAME_STATION = 8;
    private const MAX_RESULTS = 8;

    public function __construct(
        private Connection $db,
        #[Autowire(env: 'SNCF_CONNECT_URL')] private string $connectUrl,
    ) {
    }

    public function enabled(): bool
    {
        try {
            return false !== $this->db->fetchOne("SELECT 1 FROM gtfs_meta WHERE name = 'importedAt'");
        } catch (\Throwable) {
            return false;
        }
    }

    /** Période couverte par les horaires importés : [premier jour, dernier jour] (AAAA-MM-JJ). */
    public function coverage(): array
    {
        $meta = $this->db->fetchAllKeyValue("SELECT name, value FROM gtfs_meta WHERE name IN ('firstDay', 'lastDay')");

        return [$meta['firstDay'] ?? null, $meta['lastDay'] ?? null];
    }

    /**
     * Gares dont le nom contient tous les mots saisis, sans tenir compte des accents ni des tirets
     * (« saint etienne » trouve « Saint-Étienne Châteaucreux »).
     *
     * @return list<array{id: string, name: string}>
     */
    public function places(string $q): array
    {
        $words = preg_split('/[\s\-\'’]+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return [];
        }
        $esc = fn (string $w) => addcslashes($w, '%_\\');

        return $this->db->fetchAllAssociative(
            'SELECT id, name FROM gtfs_stop WHERE '.implode(' AND ', array_fill(0, count($words), 'name LIKE ?'))
            .' ORDER BY name LIKE ? DESC, CHAR_LENGTH(name), name LIMIT 10',
            [...array_map(fn ($w) => '%'.$esc($w).'%', $words), $esc($words[0]).'%'],
        );
    }

    /**
     * Gares les mieux reliées entre deux villes : « Paris » → « Beauvais » donne Paris Gare du Nord, pas Paris Est.
     * Parmi les gares correspondant à chaque saisie, retient la paire qui a le plus de trains directs.
     *
     * @return array{from: ?array{id: string, name: string}, to: ?array{id: string, name: string}}
     */
    public function bestPair(string $from, string $to): array
    {
        $a = self::inCity(array_column($this->places($from), 'name', 'id'), $from);
        $b = self::inCity(array_column($this->places($to), 'name', 'id'), $to);
        if (!$a || !$b) {
            return ['from' => $a ? ['id' => array_key_first($a), 'name' => reset($a)] : null, 'to' => $b ? ['id' => array_key_first($b), 'name' => reset($b)] : null];
        }
        $in = fn (array $ids) => implode(',', array_fill(0, count($ids), '?'));
        $best = $this->db->fetchAssociative(
            'SELECT x.stop_id AS a, y.stop_id AS b, COUNT(DISTINCT x.trip_id) AS n FROM gtfs_stop_time x
             JOIN gtfs_stop_time y ON y.trip_id = x.trip_id AND y.seq > x.seq AND y.can_alight = 1
             WHERE x.can_board = 1 AND x.stop_id IN ('.$in($a).') AND y.stop_id IN ('.$in($b).')
             GROUP BY x.stop_id, y.stop_id ORDER BY n DESC LIMIT 1',
            [...array_keys($a), ...array_keys($b)],
        );
        // Pas de train direct entre les deux villes : on retient de chaque côté la gare la plus desservie
        // (« Aix » → Aix-en-Provence TGV plutôt qu'une halte), la correspondance se cherche ensuite.
        $busiest = function (array $list) use ($in): ?string {
            $id = $this->db->fetchOne('SELECT stop_id FROM gtfs_stop_time WHERE stop_id IN ('.$in($list).') GROUP BY stop_id ORDER BY COUNT(*) DESC LIMIT 1', array_keys($list));

            return false === $id ? null : $id;
        };
        $pick = fn (array $list, ?string $id) => null !== $id && isset($list[$id]) ? ['id' => $id, 'name' => $list[$id]] : ['id' => array_key_first($list), 'name' => reset($list)];
        if (!$best) {
            return ['from' => $pick($a, $busiest($a)), 'to' => $pick($b, $busiest($b))];
        }

        return ['from' => $pick($a, $best['a'] ?? null), 'to' => $pick($b, $best['b'] ?? null)];
    }

    /**
     * Trajets de $from à $to partant le jour $day (AAAA-MM-JJ) à partir de $time (HH:MM) : trains directs,
     * puis avec une correspondance (même gare, ou autre gare de Paris, Lyon, Lille) quand ils arrivent plus tôt
     * ou qu'il n'y a pas de direct. Chaque trajet détaille ses tronçons (« segments »).
     */
    public function journeys(string $from, string $to, string $day, string $time): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$date || !preg_match('/^(\d{1,2}):(\d{2})$|^$/', $time, $m)) {
            throw new SncfException('Date ou heure invalide', 400);
        }
        $after = isset($m[1]) ? (int) $m[1] * 60 + (int) $m[2] : 0;
        $sql = 'SELECT t.number, t.mode, a.dep_min - :shift AS dep, b.arr_min - :shift AS arr
            FROM gtfs_stop_time a
            JOIN gtfs_stop_time b ON b.trip_id = a.trip_id AND b.seq > a.seq AND b.stop_id = :to AND b.can_alight = 1
            JOIN gtfs_trip t ON t.id = a.trip_id
            JOIN gtfs_service_date s ON s.service_id = t.service_id AND s.day = :day
            WHERE a.stop_id = :from AND a.can_board = 1 AND a.dep_min >= :after';
        // Trains du jour même, et trains de la veille qui passent après minuit (horaires > 24 h)
        $rows = array_merge(
            $this->db->fetchAllAssociative($sql, ['from' => $from, 'to' => $to, 'day' => $day, 'after' => $after, 'shift' => 0]),
            $this->db->fetchAllAssociative($sql, ['from' => $from, 'to' => $to, 'day' => $date->modify('-1 day')->format('Y-m-d'),
                'after' => $after + 1440, 'shift' => 1440]),
        );
        $names = $this->names([$from, $to]);
        $journeys = array_map(fn ($r) => [[
            'from' => $names[$from] ?? '', 'to' => $names[$to] ?? '', 'dep' => (int) $r['dep'], 'arr' => (int) $r['arr'],
            'mode' => $r['mode'], 'number' => (string) $r['number'],
        ]], $rows);
        $journeys = array_merge($journeys, $this->withConnection($from, $to, $day, $after));

        // On écarte un trajet quand un autre part au moins aussi tard et arrive au moins aussi tôt (à égalité : le moins de changements).
        $key = fn (array $j) => [$j[0]['dep'], end($j)['arr'], count($j)];
        $kept = [];
        foreach ($journeys as $j) {
            [$dep, $arr, $n] = $key($j);
            foreach ($journeys as $o) {
                [$od, $oa, $on] = $key($o);
                if ($o !== $j && $od >= $dep && $oa <= $arr && ($od > $dep || $oa < $arr || $on < $n)) {
                    continue 2;
                }
            }
            $kept[implode('|', [$dep, $arr, $n])] ??= $j;
        }
        usort($kept, fn ($x, $y) => $key($x) <=> $key($y));

        return array_map(fn (array $j) => [
            'depTime' => self::hm($j[0]['dep']), 'arrTime' => self::hm(end($j)['arr']),
            'mode' => implode(' + ', array_unique(array_column($j, 'mode'))), 'transfers' => count($j) - 1,
            'durationMin' => end($j)['arr'] - $j[0]['dep'],
            'numbers' => implode(' + ', array_filter(array_column($j, 'number'), 'strlen')),
            'segments' => array_map(fn ($s) => ['from' => $s['from'], 'to' => $s['to'], 'depTime' => self::hm($s['dep']),
                'arrTime' => self::hm($s['arr']), 'mode' => $s['mode'], 'number' => $s['number'], 'nextDay' => $s['dep'] >= 1440], $j),
        ], array_slice($kept, 0, self::MAX_RESULTS));
    }

    /** Trajets en deux trains : premier train depuis $from, second jusqu'à $to, changement dans la même ville. */
    private function withConnection(string $from, string $to, string $day, int $after): array
    {
        // Gares atteignables depuis le départ, et gares d'où l'on rejoint l'arrivée, ce jour-là.
        $first = $this->db->fetchAllAssociative(
            'SELECT a.trip_id AS trip, t.number, t.mode, a.dep_min AS dep, x.stop_id AS stop, x.arr_min AS arr
             FROM gtfs_stop_time a
             JOIN gtfs_trip t ON t.id = a.trip_id
             JOIN gtfs_service_date s ON s.service_id = t.service_id AND s.day = :day
             JOIN gtfs_stop_time x ON x.trip_id = a.trip_id AND x.seq > a.seq AND x.can_alight = 1
             WHERE a.stop_id = :from AND a.can_board = 1 AND a.dep_min >= :after',
            ['from' => $from, 'day' => $day, 'after' => $after],
        );
        $second = $this->db->fetchAllAssociative(
            'SELECT b.trip_id AS trip, t.number, t.mode, y.stop_id AS stop, y.dep_min AS dep, b.arr_min AS arr
             FROM gtfs_stop_time b
             JOIN gtfs_trip t ON t.id = b.trip_id
             JOIN gtfs_service_date s ON s.service_id = t.service_id AND s.day = :day
             JOIN gtfs_stop_time y ON y.trip_id = b.trip_id AND y.seq < b.seq AND y.can_board = 1
             WHERE b.stop_id = :to AND b.can_alight = 1 AND y.dep_min >= :after',
            ['to' => $to, 'day' => $day, 'after' => $after],
        );
        if (!$first || !$second) {
            return [];
        }
        $names = $this->names(array_merge([$from, $to], array_column($first, 'stop'), array_column($second, 'stop')));
        $place = fn (string $id) => [$id, self::city($names[$id] ?? '')];
        [, $fromCity] = $place($from);
        [, $toCity] = $place($to);

        // Seconds trains indexés par ville (ou gare) de correspondance.
        $bySpot = [];
        foreach ($second as $b) {
            [$id, $city] = $place($b['stop']);
            if ($id !== $from && (null === $city || $city !== $fromCity)) {
                $bySpot[$city ?? $id][] = $b;
            }
        }
        $best = []; // par premier train : la combinaison qui arrive le plus tôt
        foreach ($first as $a) {
            [$id, $city] = $place($a['stop']);
            if ($id === $to || (null !== $city && $city === $toCity)) {
                continue;
            }
            foreach ($bySpot[$city ?? $id] ?? [] as $b) {
                if ($b['trip'] === $a['trip']) {
                    continue;
                }
                $wait = $b['stop'] === $a['stop'] ? self::SAME_STATION : self::CITY_TRANSFER[$city] ?? PHP_INT_MAX;
                if ((int) $b['dep'] < (int) $a['arr'] + $wait) {
                    continue;
                }
                $k = $a['trip'];
                if (!isset($best[$k]) || (int) $b['arr'] < $best[$k][1]['arr'] || ((int) $b['arr'] === $best[$k][1]['arr'] && (int) $b['dep'] > $best[$k][1]['dep'])) {
                    $best[$k] = [
                        ['from' => $names[$from] ?? '', 'to' => $names[$a['stop']] ?? '', 'dep' => (int) $a['dep'], 'arr' => (int) $a['arr'], 'mode' => $a['mode'], 'number' => (string) $a['number']],
                        ['from' => $names[$b['stop']] ?? '', 'to' => $names[$to] ?? '', 'dep' => (int) $b['dep'], 'arr' => (int) $b['arr'], 'mode' => $b['mode'], 'number' => (string) $b['number']],
                    ];
                }
            }
        }

        return array_values($best);
    }

    /** @return array<string, string> nom des gares par identifiant */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        return $ids ? $this->db->fetchAllKeyValue('SELECT id, name FROM gtfs_stop WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')', $ids) : [];
    }

    /**
     * Garde les gares de la ville saisie elle-même quand il y en a : « Marseille » → Marseille Saint-Charles,
     * pas Marseille-en-Beauvaisis (le tiret signale une autre commune). « Saint Brieuc » trouve « Saint-Brieuc ».
     *
     * @param array<string, string> $stations nom par identifiant
     */
    private static function inCity(array $stations, string $query): array
    {
        $plain = fn (string $x) => preg_replace('/\p{Mn}/u', '', \Normalizer::normalize(mb_strtolower($x), \Normalizer::FORM_D));
        $words = preg_split('/[\s\-\'’]+/u', $plain(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        $pattern = '/^'.implode('[\s\-\'’]+', array_map(fn ($w) => preg_quote($w, '/'), $words)).'(\s|$)/u';
        $same = array_filter($stations, fn ($name) => preg_match($pattern, $plain($name)));

        return $same ?: $stations;
    }

    /** « Paris Montparnasse Hall 1 - 2 » → « paris » pour les villes où l'on change de gare ; null sinon. */
    private static function city(string $name): ?string
    {
        $first = mb_strtolower(preg_split('/[\s\-]+/u', trim($name))[0] ?? '');

        return isset(self::CITY_TRANSFER[$first]) ? $first : null;
    }

    public function connectUrl(string $origin = '', string $destination = '', string $day = ''): string
    {
        return strtr($this->connectUrl, ['{origin}' => rawurlencode($origin), '{destination}' => rawurlencode($destination),
            '{date}' => rawurlencode($day)]);
    }

    /** 1510 → « 1h10 (+1) » : arrivée le lendemain. */
    private static function hm(int $min): string
    {
        $s = intdiv($min % 1440, 60).'h'.sprintf('%02d', $min % 60);

        return $min >= 1440 ? "$s (+1)" : $s;
    }
}
