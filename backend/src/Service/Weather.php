<?php

namespace App\Service;

use App\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prévisions météo de la ville d'une date (feuille de route), par Open-Meteo : gratuit, sans clé ; seul le nom de la ville est
 * envoyé. Parmi les villes du même nom, celle du code postal de l'adresse, puis du pays ou de la région cités (« Saint-Louis (Sénégal) »),
 * sinon celle de France. Prévisions jusqu'à 16 jours ; mises en cache 3 heures (la position de la ville, 30 jours ; un échec n'est pas gardé). Un service injoignable
 * ne bloque jamais le PDF : la météo est simplement absente. Désactivable avec WEATHER_ENABLED=0.
 */
class Weather
{
    public const HORIZON_DAYS = 16;

    /** Codes météo WMO → libellé et symbole */
    private const CODES = [
        0 => ['Ensoleillé', '☀'], 1 => ['Plutôt ensoleillé', '☀'], 2 => ['Éclaircies', '☁'], 3 => ['Couvert', '☁'],
        45 => ['Brouillard', '≈'], 48 => ['Brouillard givrant', '≈'],
        51 => ['Bruine légère', '☂'], 53 => ['Bruine', '☂'], 55 => ['Bruine forte', '☂'], 56 => ['Bruine verglaçante', '☂'], 57 => ['Bruine verglaçante', '☂'],
        61 => ['Pluie faible', '☂'], 63 => ['Pluie', '☂'], 65 => ['Forte pluie', '☂'], 66 => ['Pluie verglaçante', '☂'], 67 => ['Pluie verglaçante', '☂'],
        71 => ['Neige faible', '❄'], 73 => ['Neige', '❄'], 75 => ['Forte neige', '❄'], 77 => ['Grésil', '❄'],
        80 => ['Averses', '☂'], 81 => ['Averses', '☂'], 82 => ['Fortes averses', '☂'], 85 => ['Averses de neige', '❄'], 86 => ['Averses de neige', '❄'],
        95 => ['Orage', '⚡'], 96 => ['Orage et grêle', '⚡'], 99 => ['Orage et grêle', '⚡'],
    ];

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'bool:default::WEATHER_ENABLED')] private ?bool $enabled = true,
    ) {
    }

    /**
     * @return array{city: string, days: list<array{day: string, label: string, icon: string, min: int, max: int, rain: ?int}>, availableFrom: ?string}|null
     *                                                                     availableFrom : date à partir de laquelle des prévisions manquantes seront disponibles
     */
    public function forEvent(Event $ev, ?\DateTimeImmutable $today = null): ?array
    {
        $city = trim($ev->city);
        if (false === $this->enabled || '' === $city) {
            return null;
        }
        $today ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $last = $today->modify('+'.(self::HORIZON_DAYS - 1).' days');
        $dates = array_values(array_filter($ev->spanDates(), fn ($d) => $d >= $today));
        if (!$dates) {
            return null; // date passée
        }
        $inRange = array_values(array_filter($dates, fn ($d) => $d <= $last));
        [$days, $place] = [[], null];
        if ($inRange && ($place = $this->place($city, $ev->address))) {
            $forecast = $this->forecast($place, reset($inRange), end($inRange));
            foreach ($inRange as $d) {
                if ($f = $forecast[$d->format('Y-m-d')] ?? null) {
                    $days[] = ['day' => $d->format('Y-m-d')] + $f;
                }
            }
        }
        $later = array_filter($dates, fn ($d) => $d > $last);
        $availableFrom = $later ? min($later)->modify('-'.(self::HORIZON_DAYS - 1).' days')->format('Y-m-d') : null;
        if (!$days && !$availableFrom) {
            return null; // service injoignable ou ville inconnue
        }

        return ['city' => $place['name'] ?? $city, 'days' => $days, 'availableFrom' => $availableFrom];
    }

    /** @return array{name: string, latitude: float, longitude: float}|null */
    private function place(string $city, string $address = ''): ?array
    {
        // « Saint-Louis (Sénégal) » ou « Valence, Espagne » : le nom cherché, et la précision qui aide à choisir
        $name = trim((string) preg_replace('/\s*(\(.*\)|,.*)$/u', '', $city)) ?: $city;
        $context = $city.' '.$address;

        return $this->cache->get('weather.place2.'.md5(mb_strtolower($name.'|'.$context)), function (ItemInterface $item, bool &$save) use ($name, $context) {
            $item->expiresAfter(30 * 86400);
            try {
                $r = $this->http->request('GET', 'https://geocoding-api.open-meteo.com/v1/search', [
                    'query' => ['name' => $name, 'count' => 10, 'language' => 'fr', 'format' => 'json'], 'timeout' => 5,
                ])->toArray();
                $p = self::pick($r['results'] ?? [], $context);

                return $p ? ['name' => self::label($p), 'latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude']] : null;
            } catch (\Throwable $e) {
                $save = false; // pas de cache en cas d'échec : nouvel essai à la prochaine ouverture
                $this->logger->warning('Météo : ville introuvable ({city}) : {error}', ['city' => $name, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Ville retenue parmi les homonymes (résultats classés par population) : code postal de l'adresse (exact, puis département),
     * pays ou région cités dans la ville ou l'adresse, puis France métropolitaine, puis pays voisins francophones, puis la plus peuplée.
     */
    public static function pick(array $results, string $context): ?array
    {
        if (!$results) {
            return null;
        }
        $norm = fn (string $s) => ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s))).' ';
        $text = $norm($context);
        preg_match_all('/(?<!\d)(\d{5})(?!\d)/', $context, $m);
        $codes = $m[1];
        $best = null;
        foreach (array_values($results) as $i => $r) {
            $posts = array_map(fn ($c) => substr((string) $c, 0, 5), (array) ($r['postcodes'] ?? []));
            $score = 0;
            foreach ($codes as $c) {
                $score = max($score, in_array($c, $posts, true) ? 100 : (array_filter($posts, fn ($x) => substr($x, 0, 2) === substr($c, 0, 2)) && 'FR' === ($r['country_code'] ?? '') ? 60 : 0));
            }
            foreach (['country', 'admin1', 'admin2'] as $k) {
                $v = trim($norm((string) ($r[$k] ?? '')));
                if ('' !== $v && mb_strlen($v) > 2 && str_contains($text, ' '.$v.' ') && trim($norm((string) ($r['name'] ?? ''))) !== $v) {
                    $score = max($score, 'country' === $k ? 40 : 50);
                }
            }
            $score += ['FR' => 20, 'BE' => 10, 'CH' => 10, 'LU' => 10, 'MC' => 10][$r['country_code'] ?? ''] ?? 0;
            if (null === $best || $score > $best[0]) {
                $best = [$score, $r];
            }
        }

        return $best[1];
    }

    /** « Saint-Louis (Haut-Rhin) », « Saint-Louis (Sénégal) » : pour vérifier d'un coup d'œil sur la feuille de route. */
    private static function label(array $p): string
    {
        $where = 'FR' === ($p['country_code'] ?? '') ? ($p['admin2'] ?? $p['admin1'] ?? '') : ($p['country'] ?? '');

        return (string) $p['name'].('' !== $where && $where !== $p['name'] ? ' ('.$where.')' : '');
    }

    /** @return array<string, array{label: string, icon: string, min: int, max: int, rain: ?int}> par jour */
    private function forecast(array $place, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $key = sprintf('weather.forecast.%s.%s.%s.%s', round($place['latitude'], 2), round($place['longitude'], 2), $from->format('Ymd'), $to->format('Ymd'));

        return $this->cache->get(str_replace(['-', ':'], '_', $key), function (ItemInterface $item, bool &$save) use ($place, $from, $to) {
            $item->expiresAfter(3 * 3600);
            try {
                $d = $this->http->request('GET', 'https://api.open-meteo.com/v1/forecast', ['query' => [
                    'latitude' => $place['latitude'], 'longitude' => $place['longitude'], 'timezone' => 'Europe/Paris',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                    'start_date' => $from->format('Y-m-d'), 'end_date' => $to->format('Y-m-d'),
                ], 'timeout' => 5])->toArray()['daily'] ?? [];
                $out = [];
                foreach ($d['time'] ?? [] as $i => $day) {
                    [$label, $icon] = self::CODES[(int) ($d['weather_code'][$i] ?? -1)] ?? ['—', ''];
                    $out[$day] = ['label' => $label, 'icon' => $icon, 'min' => (int) round((float) ($d['temperature_2m_min'][$i] ?? 0)),
                        'max' => (int) round((float) ($d['temperature_2m_max'][$i] ?? 0)),
                        'rain' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null];
                }

                return $out;
            } catch (\Throwable $e) {
                $save = false;
                $this->logger->warning('Météo indisponible : {error}', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }
}
