<?php

namespace App\Tests;

use App\Entity\Event;
use App\Service\Weather;

/** Météo de la feuille de route : prévisions à 16 jours, jours trop lointains annoncés, service injoignable sans effet. */
class WeatherTest extends ApiTestCase
{
    public function testForecastForEventDays(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $d = fn (int $n) => $today->modify("+$n days")->format('Y-m-d');
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Théâtre', 'city' => 'Beauvais', 'firstDate' => $d(3), 'lastDate' => $d(4)]);
        $event = fn () => $this->em()->find(Event::class, $ev['id']);
        $weather = static::getContainer()->get(Weather::class);

        // Service injoignable : pas de météo, sans erreur ; le PDF se génère
        self::assertNull($weather->forEvent($event()));
        $this->client->request('GET', "/api/events/{$ev['id']}/pdf/roadmap");
        self::assertSame(200, $this->code());

        $fake = static::getContainer()->get(FakeHttp::class);
        $fake->forecast = ['time' => [$d(3), $d(4)], 'weather_code' => [0, 61], 'temperature_2m_max' => [21.4, 17.6], 'temperature_2m_min' => [11.2, 9.5],
            'precipitation_probability_max' => [5, 80]];
        $w = static::getContainer()->get(Weather::class)->forEvent($event(), $today->modify('+1 day')); // autre jour : pas de cache
        self::assertSame('Beauvais', $w['city']);
        self::assertSame([[$d(3), 'Ensoleillé', 21, 11, 5], [$d(4), 'Pluie faible', 18, 10, 80]],
            array_map(fn ($x) => [$x['day'], $x['label'], $x['max'], $x['min'], $x['rain']], $w['days']));
        self::assertNull($w['availableFrom']);

        // Date dans un mois : pas encore de prévisions, date à partir de laquelle elles seront disponibles
        $later = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'city' => 'Beauvais', 'firstDate' => $d(30)]);
        $w = $weather->forEvent($this->em()->find(Event::class, $later['id']), $today);
        self::assertSame([[], $d(15)], [$w['days'], $w['availableFrom']]);
        $this->client->request('GET', "/api/events/{$later['id']}/pdf/roadmap");
        self::assertSame(200, $this->code());

        // Date passée ou sans ville : rien
        $past = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => $d(-10)]);
        self::assertNull($weather->forEvent($this->em()->find(Event::class, $past['id']), $today));
    }

    /** Homonymes (réponse réelle d'Open-Meteo pour « Saint-Louis », classée par population) : la bonne ville est retenue. */
    public function testPickHomonym(): void
    {
        $r = [
            ['name' => 'Saint-Louis', 'latitude' => 16.02, 'longitude' => -16.49, 'country_code' => 'SN', 'country' => 'Sénégal', 'admin1' => 'Saint-Louis'],
            ['name' => 'Saint-Louis', 'latitude' => 38.63, 'longitude' => -90.2, 'country_code' => 'US', 'country' => 'États-Unis', 'admin1' => 'Missouri', 'postcodes' => ['63101']],
            ['name' => 'Saint-Louis', 'latitude' => -21.29, 'longitude' => 55.41, 'country_code' => 'RE', 'admin1' => 'Réunion', 'admin2' => 'Réunion', 'postcodes' => ['97450']],
            ['name' => 'Saint-Louis', 'latitude' => 47.59, 'longitude' => 7.56, 'country_code' => 'FR', 'country' => 'France', 'admin1' => 'Grand Est', 'admin2' => 'Haut-Rhin', 'postcodes' => ['68300', '68301 CEDEX']],
            ['name' => 'Saint-Louis', 'latitude' => 48.72, 'longitude' => 7.2, 'country_code' => 'FR', 'country' => 'France', 'admin1' => 'Grand Est', 'admin2' => 'Moselle', 'postcodes' => ['57820']],
        ];
        $at = fn (string $context) => Weather::pick($r, $context)['latitude'];
        self::assertSame(47.59, $at('Saint-Louis'), 'France avant la ville la plus peuplée');
        self::assertSame(48.72, $at('Saint-Louis 2 rue du Théâtre, 57820 Saint-Louis'), 'code postal de l\'adresse');
        self::assertSame(47.59, $at('Saint-Louis 68301 Saint-Louis Cedex'), 'code CEDEX');
        self::assertSame(48.72, $at('Saint-Louis (Moselle)'), 'département cité');
        self::assertSame(16.02, $at('Saint-Louis (Sénégal)'), 'pays cité');
        self::assertSame(38.63, $at('Saint-Louis, Missouri'));
        self::assertSame(-21.29, $at('Saint-Louis 97450 La Réunion'));
        self::assertNull(Weather::pick([], 'x'));
    }

    /** La ville affichée précise le département (ou le pays) retenu, et la précision entre parenthèses n'est pas cherchée. */
    public function testPlaceLabelAndQuery(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $d = fn (int $n) => $today->modify("+$n days")->format('Y-m-d');
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Théâtre', 'city' => 'Saint-Louis (Haut-Rhin)', 'firstDate' => $d(2)]);
        $fake = static::getContainer()->get(FakeHttp::class);
        $fake->forecast = ['time' => [$d(2)], 'weather_code' => [0], 'temperature_2m_max' => [10], 'temperature_2m_min' => [2], 'precipitation_probability_max' => [0]];
        $fake->places = [['name' => 'Saint-Louis', 'latitude' => 16.02, 'longitude' => -16.49, 'country_code' => 'SN', 'country' => 'Sénégal'],
            ['name' => 'Saint-Louis', 'latitude' => 47.59, 'longitude' => 7.56, 'country_code' => 'FR', 'country' => 'France', 'admin1' => 'Grand Est', 'admin2' => 'Haut-Rhin']];
        $w = static::getContainer()->get(Weather::class)->forEvent($this->em()->find(Event::class, $ev['id']), $today->modify('+1 day'));
        self::assertSame('Saint-Louis (Haut-Rhin)', $w['city']);
        $geo = array_values(array_filter($fake->calls, fn ($u) => str_contains($u, 'geocoding')));
        self::assertStringContainsString('name=Saint-Louis&', $geo[0]);
        self::assertStringContainsString('47.59', implode(' ', array_filter($fake->calls, fn ($u) => str_contains($u, 'forecast'))));
    }
}
