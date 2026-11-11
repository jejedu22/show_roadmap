<?php

namespace App\Tests;

/** Séjour pré-rempli d'après les voyages : arrivée / départ avant ou après 12h00, nuitées, petits-déjeuners. */
class StayFromTravelTest extends ApiTestCase
{
    public function testDaysFollowTravel(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $id = $ev['id'];
        $pid = $ev['participations'][0]['id'];
        $url = "/api/events/$id/items/leg";
        // [nuitée, repas, petit-déjeuner] par jour
        $grid = fn ($ev) => array_map(fn ($d) => [$d['night'], $d['meals'], $d['breakfast']], $ev['participations'][0]['days']);

        // Aller la veille, arrivée après 12h00 : dîner seulement
        $ev = $this->call('POST', $url, ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'depTime' => '10h00', 'arrTime' => '14h30']);
        $out = $ev['participations'][0]['legs'][0]['id'];
        self::assertSame([1, 1, 0], $grid($ev)['2027-01-18']);
        self::assertContains('2027-01-18', $ev['dates'], 'jour d\'arrivée affiché dans la grille');

        // Retour le lendemain de la dernière, départ avant 12h00 : aucun repas ce jour-là, petit-déjeuner compté
        $ev = $this->call('POST', $url, ['participationId' => $pid, 'direction' => 'return', 'day' => '2027-01-21', 'depTime' => '9h15']);
        $back = array_values(array_filter($ev['participations'][0]['legs'], fn ($l) => 'return' === $l['direction']))[0]['id'];
        self::assertSame(['2027-01-18' => [1, 1, 0], '2027-01-19' => [1, 2, 1], '2027-01-20' => [1, 2, 1], '2027-01-21' => [0, 0, 1]], $grid($ev));

        // Départ après 12h00 : déjeuner compté ; arrivée avant 12h00 (dernier trajet d'une correspondance) : déjeuner et dîner
        $ev = $this->call('PUT', "$url/$back", ['direction' => 'return', 'day' => '2027-01-21', 'depTime' => '15h40']);
        self::assertSame([0, 1, 1], $grid($ev)['2027-01-21']);
        $ev = $this->call('PUT', "$url/$out", ['direction' => 'outbound', 'day' => '2027-01-18', 'depTime' => '7h00', 'arrTime' => '9h00']);
        $ev = $this->call('POST', $url, ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'depTime' => '9h30', 'arrTime' => '11h45']);
        self::assertSame([1, 2, 0], $grid($ev)['2027-01-18']);

        // Plus d'aller : retour aux dates de la fiche (arrivée le jour de la 1re)
        foreach (array_filter($ev['participations'][0]['legs'], fn ($l) => 'outbound' === $l['direction']) as $l) {
            $ev = $this->call('DELETE', "$url/{$l['id']}");
        }
        self::assertArrayNotHasKey('2027-01-18', $grid($ev));
        self::assertSame([1, 2, 0], $grid($ev)['2027-01-19']);

        // Ordre des étapes : Voyages avant Séjour
        $keys = array_column($ev['readiness']['steps'], 'key');
        self::assertLessThan(array_search('stay', $keys, true), array_search('travel', $keys, true));
    }
}
