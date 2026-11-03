<?php

namespace App\Tests;

/** Dépenses et remboursements sur les trajets : liste des types, coûts, droits, absence de la feuille de route. */
class TravelExpensesTest extends ApiTestCase
{
    public function testExpensesAddToTravelCosts(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        self::assertContains('Taxi', $this->call('GET', '/api/lists')['expense']);
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $pid = $ev['participations'][0]['id'];
        $base = "/api/events/{$ev['id']}/items";
        $this->call('POST', "$base/leg", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'price' => '60']);

        $this->call('POST', "$base/expense", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Trottinette', 'amount' => '5']);
        self::assertSame(400, $this->code(), 'type hors liste refusé');
        $this->call('POST', "$base/expense", ['participationId' => $pid, 'direction' => 'outbound', 'kind' => 'Taxi', 'amount' => '5']);
        self::assertSame(400, $this->code(), 'date obligatoire');

        $this->call('POST', "$base/expense", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'taxi', 'amount' => '25,50', 'comment' => 'Gare → théâtre']);
        $ev = $this->call('POST', "$base/expense", ['participationId' => $pid, 'direction' => 'return', 'day' => '2027-01-20', 'kind' => 'Remboursement de billet', 'amount' => '-15']);
        $x = $ev['participations'][0]['expenses'];
        self::assertSame([['outbound', 'Taxi', 25.5, 'Gare → théâtre'], ['return', 'Remboursement de billet', -15.0, '']],
            array_map(fn ($e) => [$e['direction'], $e['kind'], $e['amount'], $e['comment']], $x));
        self::assertSame([60.0, 10.5, 70.5], [$ev['quote']['travelTickets'], $ev['quote']['travelExpenses'], $ev['quote']['travelTotal']]);
        self::assertSame([85.5, -15.0, 10.5], [$ev['personCosts'][0]['outbound'], $ev['personCosts'][0]['return'], $ev['personCosts'][0]['expenses']]);
        self::assertSame(70.5, $ev['quote']['lines'][1]['amount'], 'transport équipe du devis');
        self::assertSame(2, $ev['readiness']['legsNeeded']);
        self::assertSame(1, $ev['readiness']['legsCovered'], 'une dépense ne compte pas comme un trajet');

        $ev = $this->call('PUT', "$base/expense/{$x[0]['id']}", ['direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '30']);
        self::assertSame(15.0, $ev['quote']['travelExpenses']);
        $ev = $this->call('DELETE', "$base/expense/{$x[1]['id']}");
        self::assertCount(1, $ev['participations'][0]['expenses']);

        // Sans « Voir les montants » : lignes masquées, saisie refusée
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->grant(['ed@test.io'], ['company.view', 'travel.edit'], [$cid]);
        $this->call('POST', '/api/logout');
        $this->login('ed@test.io', 'ed-password-12345');
        self::assertSame([], $this->call('GET', "/api/events/{$ev['id']}")['participations'][0]['expenses']);
        $this->call('POST', "$base/expense", ['participationId' => $pid, 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '5']);
        self::assertSame(403, $this->code());
    }
}
