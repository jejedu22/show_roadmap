<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Recherche de trains directs dans les horaires théoriques SNCF importés (voir GtfsImporter). */
class SncfTimetable
{
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
        $a = array_column($this->places($from), 'name', 'id');
        $b = array_column($this->places($to), 'name', 'id');
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
        $pick = fn (array $list, ?string $id) => null !== $id && isset($list[$id]) ? ['id' => $id, 'name' => $list[$id]] : ['id' => array_key_first($list), 'name' => reset($list)];

        return ['from' => $pick($a, $best['a'] ?? null), 'to' => $pick($b, $best['b'] ?? null)];
    }

    /** Trains directs de $from à $to partant le jour $day (AAAA-MM-JJ) à partir de $time (HH:MM). */
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
        usort($rows, fn ($x, $y) => $x['dep'] <=> $y['dep']);

        return array_map(fn ($r) => [
            'depTime' => self::hm((int) $r['dep']), 'arrTime' => self::hm((int) $r['arr']), 'mode' => $r['mode'],
            'transfers' => 0, 'durationMin' => (int) $r['arr'] - (int) $r['dep'], 'numbers' => (string) $r['number'],
        ], array_slice($rows, 0, 8));
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
