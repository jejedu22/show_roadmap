<?php

namespace App\Tests;

/** Hébergements : site web (lien contrôlé, repris à la duplication). */
class LodgingTest extends ApiTestCase
{
    public function testLodgingWebsite(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $url = "/api/events/{$ev['id']}/items/lodging";

        $this->call('POST', $url, ['name' => 'Hôtel', 'website' => 'javascript:alert(1)']);
        self::assertSame(400, $this->code(), 'lien non http(s) refusé');
        $ev = $this->call('POST', $url, ['name' => 'Hôtel', 'website' => 'https://hotel.example/fr']);
        self::assertSame('https://hotel.example/fr', $ev['lodgings'][0]['website']);
        $ev = $this->call('PUT', "$url/{$ev['lodgings'][0]['id']}", ['name' => 'Hôtel', 'website' => '']);
        self::assertSame('', $ev['lodgings'][0]['website']);

        $this->call('PUT', "$url/{$ev['lodgings'][0]['id']}", ['name' => 'Hôtel', 'website' => 'https://hotel.example']);
        $copy = $this->call('POST', "/api/events/{$ev['id']}/duplicate");
        self::assertSame('https://hotel.example', $copy['lodgings'][0]['website']);
    }
}
