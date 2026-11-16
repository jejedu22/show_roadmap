<?php

namespace App\Tests;

/** Dates enchaînées : le retour de la date précédente est repris comme aller (et l'aller de la suivante comme retour). */
class LegsCopyTest extends ApiTestCase
{
    public function testCopyLegsBetweenChainedDates(): void
    {
        $this->login();
        [, [$a, $b]] = $this->buildCompany();
        $mk = function (int $show, string $venue, string $from, string $to): array {
            $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => $venue, 'firstDate' => $from, 'lastDate' => $to, 'personIds' => []]);
            $people = array_column($ev['availablePeople'], 'id', 'firstName');

            return $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_values($people)]);
        };
        $one = $mk($a, 'Beauvais', '2027-03-10', '2027-03-12');
        $two = $mk($b, 'Lyon', '2027-03-14', '2027-03-15'); // autre spectacle de la même compagnie
        $pid = array_column(array_map(fn ($p) => ['n' => $p['person']['firstName'], 'id' => $p['id']], $one['participations']), 'id', 'n');
        $leg = fn ($ev, $p, $dir, $day, $o, $d, $t) => $this->call('POST', "/api/events/{$ev['id']}/items/leg",
            ['participationId' => $p, 'direction' => $dir, 'day' => $day, 'origin' => $o, 'destination' => $d, 'depTime' => $t, 'arrTime' => '12h00',
                'mode' => 'Train', 'reference' => 'XYZ789', 'price' => '45', 'bookingUrl' => 'https://www.sncf-connect.com/']);
        $leg($one, $pid['Anna'], 'return', '2027-03-13', 'Beauvais', 'Paris', '8h00');
        $leg($one, $pid['Anna'], 'return', '2027-03-13', 'Paris', 'Lyon', '10h00');
        $leg($one, $pid['Luc'], 'outbound', '2027-03-09', 'Lille', 'Beauvais', '9h00'); // aller de la 1re date : pas proposé pour la 2e

        $sources = $this->call('GET', "/api/events/{$two['id']}/leg-sources");
        self::assertCount(1, $sources);
        self::assertSame(['before', $one['id']], [$sources[0]['position'], $sources[0]['event']['id']]);
        self::assertSame(['Beauvais', 'Paris'], array_column($sources[0]['legs'], 'origin'), 'correspondance dans l\'ordre');
        self::assertSame(['outbound', 'outbound'], array_column($sources[0]['legs'], 'direction'));
        $ids = array_column($sources[0]['legs'], 'id');

        // Copie sans prix (par défaut) : aller ici, référence et lien repris, prix à 0
        $ev = $this->call('POST', "/api/events/{$two['id']}/copy-legs", ['legIds' => $ids]);
        $anna = array_values(array_filter($ev['participations'], fn ($p) => 'Anna' === $p['person']['firstName']))[0];
        self::assertCount(2, $anna['legs']);
        self::assertEquals(['outbound', 'Paris', 'Lyon', 'XYZ789', 0, 'https://www.sncf-connect.com/'],
            [$anna['legs'][1]['direction'], $anna['legs'][1]['origin'], $anna['legs'][1]['destination'], $anna['legs'][1]['reference'], $anna['legs'][1]['price'], $anna['legs'][1]['bookingUrl']]);
        self::assertNotEmpty($anna['days'], 'séjour recalculé d\'après les trajets');

        // Déjà présents : signalés, et une nouvelle copie est refusée
        self::assertSame([true, true], array_column($this->call('GET', "/api/events/{$two['id']}/leg-sources")[0]['legs'], 'already'));
        $this->call('POST', "/api/events/{$two['id']}/copy-legs", ['legIds' => $ids]);
        self::assertSame(400, $this->code());

        // Dans l'autre sens : l'aller de la 2e date devient le retour de la 1re, avec le prix si demandé
        $lucTwo = array_values(array_filter($ev['participations'], fn ($p) => 'Luc' === $p['person']['firstName']))[0]['id'];
        $leg($two, $lucTwo, 'outbound', '2027-03-13', 'Beauvais', 'Lyon', '15h00');
        $src = $this->call('GET', "/api/events/{$one['id']}/leg-sources")[0];
        $lucLeg = array_values(array_filter($src['legs'], fn ($l) => str_contains($l['person'], 'Luc')))[0];
        self::assertSame(['after', 'return', false], [$src['position'], $lucLeg['direction'], $lucLeg['already']]);
        self::assertTrue(array_values(array_filter($src['legs'], fn ($l) => str_contains($l['person'], 'Anna')))[0]['already'], 'copie d\'Anna = son retour d\'origine');
        $ev = $this->call('POST', "/api/events/{$one['id']}/copy-legs", ['legIds' => [$lucLeg['id']], 'withPrice' => true]);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $luc = array_values(array_filter($ev['participations'], fn ($p) => 'Luc' === $p['person']['firstName']))[0];
        $back = array_values(array_filter($luc['legs'], fn ($l) => 'return' === $l['direction']));
        self::assertEquals([45, 'Lyon'], [$back[0]['price'], $back[0]['destination']]);
    }
}
