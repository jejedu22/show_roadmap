<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Client minimal de l'API SNCF (Navitia) : recherche de gares et d'itinéraires. */
class SncfClient
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'SNCF_API_TOKEN')] private string $token,
        #[Autowire(env: 'SNCF_API_URL')] private string $baseUrl,
        #[Autowire(env: 'SNCF_CONNECT_URL')] private string $connectUrl,
    ) {
    }

    public function enabled(): bool
    {
        return '' !== $this->token;
    }

    /** @return list<array{id: string, name: string}> */
    public function places(string $q): array
    {
        $data = $this->get('/places', ['q' => $q, 'type[]' => 'stop_area', 'count' => 8]);

        return array_values(array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']],
            array_filter($data['places'] ?? [], fn ($p) => 'stop_area' === ($p['embedded_type'] ?? null))));
    }

    /** @param string $day AAAA-MM-JJ, $time HH:MM */
    public function journeys(string $from, string $to, string $day, string $time): array
    {
        $when = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "$day ".($time ?: '00:00'));
        if (!$when) {
            throw new SncfException('Date ou heure invalide', 400);
        }
        $data = $this->get('/journeys', ['from' => $from, 'to' => $to, 'datetime' => $when->format('Ymd\THis'),
            'datetime_represents' => 'departure', 'count' => 6, 'data_freshness' => 'base_schedule']);
        $out = [];
        foreach ($data['journeys'] ?? [] as $j) {
            $rides = array_values(array_filter($j['sections'] ?? [], fn ($s) => 'public_transport' === ($s['type'] ?? null)));
            if (!$rides) {
                continue;
            }
            $modes = $numbers = [];
            foreach ($rides as $s) {
                $info = $s['display_informations'] ?? [];
                $mode = $info['commercial_mode'] ?? $info['network'] ?? 'Train';
                if (!in_array($mode, $modes, true)) {
                    $modes[] = $mode;
                }
                if (!empty($info['trip_short_name'])) {
                    $numbers[] = $info['trip_short_name'];
                }
            }
            $out[] = ['depTime' => self::hm($j['departure_date_time']), 'arrTime' => self::hm($j['arrival_date_time']),
                'mode' => implode(' + ', $modes), 'transfers' => count($rides) - 1,
                'durationMin' => (int) round(($j['duration'] ?? 0) / 60), 'numbers' => implode(' / ', $numbers)];
        }

        return $out;
    }

    public function connectUrl(string $origin = '', string $destination = '', string $day = ''): string
    {
        return strtr($this->connectUrl, ['{origin}' => rawurlencode($origin), '{destination}' => rawurlencode($destination),
            '{date}' => rawurlencode($day)]);
    }

    private function get(string $path, array $query): array
    {
        if (!$this->enabled()) {
            throw new SncfException('SNCF_API_TOKEN non configuré');
        }
        try {
            $res = $this->http->request('GET', rtrim($this->baseUrl, '/').$path, ['query' => $query, 'auth_basic' => [$this->token, '']]);
            $status = $res->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SncfException('Jeton SNCF refusé');
            }
            if (404 === $status) { // Navitia : aucun itinéraire / lieu inconnu
                return [];
            }
            if ($status >= 400) {
                throw new SncfException("Erreur API SNCF ($status)");
            }

            return $res->toArray(false);
        } catch (ExceptionInterface) {
            throw new SncfException('API SNCF injoignable');
        }
    }

    private static function hm(string $dt): string
    {
        $d = \DateTimeImmutable::createFromFormat('Ymd\THis', $dt);

        return $d ? $d->format('G').'h'.$d->format('i') : '';
    }
}
