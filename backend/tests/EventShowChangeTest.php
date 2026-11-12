<?php

namespace App\Tests;

/** Une date peut être rattachée à un autre spectacle de la même compagnie (correction d'erreur). */
class EventShowChangeTest extends ApiTestCase
{
    public function testChangeShowOfEvent(): void
    {
        $this->login();
        [$cid, [$a, $b]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$a/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        self::assertSame(['Spectacle A', 'Spectacle B'], array_column($ev['companyShows'], 'title'));

        $ev = $this->call('PUT', "/api/events/{$ev['id']}", ['showId' => $b, 'venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        self::assertSame([$b, 'Spectacle B'], [$ev['showId'], $ev['showTitle']]);
        $summary = array_column($this->call('GET', "/api/companies/$cid")['events'], null, 'id')[$ev['id']];
        self::assertSame($b, $summary['showId'], 'la liste de la compagnie suit');

        // Spectacle d'une autre compagnie : refusé, la date reste inchangée
        [, [$foreign]] = $this->buildCompany('Autre cie');
        $this->call('PUT', "/api/events/{$ev['id']}", ['showId' => $foreign, 'venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        self::assertSame(400, $this->code());
        self::assertSame($b, $this->call('GET', "/api/events/{$ev['id']}")['showId']);
    }
}
