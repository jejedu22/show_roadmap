<?php

namespace App\Tests;

use App\Entity\AuditLog;

/** Droit d'accès RGPD : export JSON de toutes les données d'une personne de l'annuaire. */
class PersonExportTest extends ApiTestCase
{
    public function testExportPersonData(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $anna = array_column($ev['availablePeople'], null, 'firstName')['Anna'];
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$anna['id']]]);
        $pid = $ev['participations'][0]['id'];
        $this->call('POST', "/api/events/{$ev['id']}/items/leg", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18',
            'origin' => 'Paris', 'destination' => 'Lyon', 'reference' => 'ABC123', 'price' => '60']);
        $this->call('POST', "/api/events/{$ev['id']}/items/expense", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '12']);
        $this->call('PUT', "/api/people/{$anna['id']}", ['lastName' => 'DURAND', 'firstName' => 'Anna', 'phone' => '06 12 34 56 78', 'email' => 'anna@exemple.fr', 'diet' => 'Végétarienne']);

        $this->client->request('GET', "/api/people/{$anna['id']}/export");
        self::assertSame(200, $this->code());
        $res = $this->client->getResponse();
        self::assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $data = json_decode($res->getContent(), true);
        self::assertSame(['DURAND', '06 12 34 56 78', 'Végétarienne'], [$data['fiche']['nom'], $data['fiche']['telephone'], $data['fiche']['regime_alimentaire']]);
        self::assertSame('Cie Test', $data['export']['responsable_du_traitement']);
        self::assertCount(1, $data['dates']);
        self::assertEquals(['Salle', 'ABC123', 60], [$data['dates'][0]['lieu'], $data['dates'][0]['trajets'][0]['reference'], $data['dates'][0]['trajets'][0]['prix']]);
        self::assertEquals(12, $data['dates'][0]['depenses_et_remboursements'][0]['montant']);
        self::assertNotEmpty($data['dates'][0]['jours']);
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'person.export']), 'noté au journal');

        // Sans droit finances : montants absents ; sans droit annuaire : refusé
        $this->createUser('lea@test.io', 'lea-password-123');
        $this->grant(['lea@test.io'], ['company.view', 'people.view'], [$cid]);
        $this->login('lea@test.io', 'lea-password-123');
        $this->client->request('GET', "/api/people/{$anna['id']}/export");
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($data['export']['montants_inclus']);
        self::assertArrayNotHasKey('prix', $data['dates'][0]['trajets'][0]);
        self::assertNull($data['dates'][0]['depenses_et_remboursements']);

        $this->createUser('max@test.io', 'max-password-123');
        $this->grant(['max@test.io'], ['company.view'], [$cid]);
        $this->login('max@test.io', 'max-password-123');
        $this->client->request('GET', "/api/people/{$anna['id']}/export");
        self::assertSame(403, $this->code());
    }
}
