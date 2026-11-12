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
            array_map(fn ($e) => [$e['direction'], $e['kind'], (float) $e['amount'], $e['comment']], $x));
        self::assertEquals([60.0, 10.5, 70.5], [$ev['quote']['travelTickets'], $ev['quote']['travelExpenses'], $ev['quote']['travelTotal']]);
        self::assertEquals([85.5, -15.0, 10.5], [$ev['personCosts'][0]['outbound'], $ev['personCosts'][0]['return'], $ev['personCosts'][0]['expenses']]);
        self::assertEquals(70.5, $ev['quote']['lines'][1]['amount'], 'transport équipe du devis');

        // Budget transport : ligne en plus du transport réel de l'équipe, comptée dans le total
        $before = $ev['quote']['totalHt'];
        self::assertNotContains('Budget transport', array_column($ev['quote']['lines'], 'label'), 'pas de ligne sans budget');
        $ev = $this->call('PUT', "/api/events/{$ev['id']}/rates", ['transportBudget' => '250,00'] + array_map('strval', $ev['rates']));
        self::assertEquals(250, $ev['rates']['transportBudget']);
        self::assertEquals(['Budget transport', 250], array_values(array_column($ev['quote']['lines'], null, 'label')['Budget transport']));
        self::assertEquals($before + 250, $ev['quote']['totalHt']);
        self::assertEquals(70.5, array_column($ev['quote']['lines'], null, 'label')['Transport équipe (billets, dépenses de trajet, transports locaux)']['amount']);
        foreach (['export.xlsx', 'costs.xlsx'] as $file) {
            $this->client->request('GET', "/api/events/{$ev['id']}/$file");
            $path = tempnam(sys_get_temp_dir(), 'x');
            file_put_contents($path, $this->client->getInternalResponse()->getContent());
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Devis')->toArray(null, true, false);
            unlink($path);
            self::assertEquals(250, array_column($rows, 1, 0)['Budget transport'] ?? null, "ligne du devis dans $file");
        }
        self::assertSame(2, $ev['readiness']['legsNeeded']);
        self::assertSame(1, $ev['readiness']['legsCovered'], 'une dépense ne compte pas comme un trajet');

        $ev = $this->call('PUT', "$base/expense/{$x[0]['id']}", ['direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '30']);
        self::assertEquals(15.0, $ev['quote']['travelExpenses']);
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

    public function testCostsExcelSharedByLink(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $id = $ev['id'];
        $pid = $ev['participations'][0]['id'];
        $this->call('POST', "/api/events/$id/items/leg", ['participationId' => $pid, 'day' => '2027-01-18', 'price' => '60']);
        $this->call('POST', "/api/events/$id/items/expense", ['participationId' => $pid, 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '12']);

        $this->client->request('GET', "/api/events/$id/costs.xlsx");
        self::assertSame(200, $this->code());
        $sheets = $this->sheets($this->client->getResponse()->getContent());
        self::assertSame(['Devis', 'Tarifs', 'Par personne', 'Défraiements', 'Voyages'], $sheets);

        $this->client->request('GET', '/partage/couts/'.str_repeat('a', 64));
        self::assertSame(404, $this->code());
        $ev = $this->call('POST', "/api/events/$id/cost-share");
        $token = $ev['costShare']['token'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertNotSame($ev['share']['token'], $token, 'distinct du lien de la feuille de route');
        self::assertSame($token, $this->call('POST', "/api/events/$id/cost-share")['costShare']['token'], 'le lien reste le même');
        $this->client->request('GET', "/partage/$token");
        self::assertSame(404, $this->code(), 'le lien des coûts n\'ouvre pas la feuille de route');

        $this->call('POST', '/api/logout');
        $this->client->request('GET', "/partage/couts/$token");
        self::assertSame(200, $this->code(), 'accessible sans compte');
        self::assertStringContainsString('spreadsheetml', $this->client->getResponse()->headers->get('content-type'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('cache-control'));
        self::assertNotContains('Coordonnées', $this->sheets($this->client->getResponse()->getContent()));

        // Sans « Voir les montants » : pas de lien visible, ni création possible
        $this->createUser('sh@test.io', 'sh-password-12345');
        $this->grant(['sh@test.io'], ['company.view', 'share.manage'], [$cid]);
        $this->login('sh@test.io', 'sh-password-12345');
        self::assertNull($this->call('GET', "/api/events/$id")['costShare']['token']);
        $this->call('POST', "/api/events/$id/cost-share");
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/logout');

        $this->login();
        $this->call('DELETE', "/api/events/$id/cost-share");
        $this->call('POST', '/api/logout');
        $this->client->request('GET', "/partage/couts/$token");
        self::assertSame(404, $this->code(), 'lien révoqué');
    }

    /** @return list<string> noms des onglets d'un classeur */
    private function sheets(string $xlsx): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $xlsx);
        try {
            return \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetNames();
        } finally {
            unlink($file);
        }
    }
}
