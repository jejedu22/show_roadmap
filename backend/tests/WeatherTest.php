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
}
