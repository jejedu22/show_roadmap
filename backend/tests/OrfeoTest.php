<?php

namespace App\Tests;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\Person;

/** Intégration Orfeo, étape 1 : connexion par clé d'API, récupération des dates avec relecture, puis import. */
class OrfeoTest extends ApiTestCase
{
    private const KEY = 'cle-orfeo-valide';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot(); // garde la même API Orfeo simulée d'une requête à l'autre
    }

    private function fake(): FakeHttp
    {
        return static::getContainer()->get(FakeHttp::class);
    }

    /** Une date Orfeo (projet 12) au format de l'API : 2 représentations, 3 participants, hôtel, voyage, contact. */
    private function orfeoData(): void
    {
        $page = fn (array $results, ?string $next = null) => ['count' => count($results), 'next' => $next, 'previous' => null, 'results' => $results];
        $dt = fn (string $s) => $s.'+01:00';
        $this->fake()->orfeo = [
            '/api/organizationprofile/' => $page([['pk' => 1, 'name' => 'Compagnie Oiseaux (Orfeo)', 'is_default' => true]]),
            '/api/project/' => $page([['pk' => 12, 'title' => 'Concert à Lorient', 'spectacle' => 'Les oiseaux', 'venue' => 'Le Moulin']]),
            '/api/project/12/' => ['pk' => 12, 'title' => 'Concert à Lorient', 'start_date' => '2026-12-02', 'end_date' => '2026-12-04',
                'spectacle' => ['pk' => 7, 'name' => 'Les oiseaux de passage'], 'status' => ['pk' => 3, 'name' => 'Confirmé'], 'is_cancelled' => false,
                'venue' => ['pk' => 4, 'name' => 'Théâtre du Moulin', 'address1' => '4 rue du Port', 'zipcode' => '56100', 'city' => 'Lorient'],
                'planning_notes' => '<p>Accès par le quai</p>', 'notes' => '', 'performances' => []],
            // Participants sur deux pages
            '/api/project_participant/?project=12' => $page([
                ['pk' => 1, 'entity' => ['pk' => 101, 'name' => 'DURAND Anna'], 'function' => 'Danseuse', 'status' => 'confirmed'],
                ['pk' => 2, 'entity' => ['pk' => 102, 'name' => 'Yann Le Bras'], 'function' => 'Régisseur lumière', 'status' => 'to-confirm'],
            ], 'https://orfeo.test/api/project_participant/?page=2&project=12'),
            '/api/project_participant/?project=12#2' => $page([
                ['pk' => 3, 'entity' => ['pk' => 103, 'name' => 'Absent'], 'function' => 'Danseur', 'status' => 'not-available'],
                ['pk' => 4, 'entity' => ['pk' => 0], 'function' => 'Musicien', 'status' => 'unnamed'],
            ]),
            '/api/employee/101/' => ['pk' => 101, 'first_name' => 'Anna', 'last_name' => 'Durand', 'city' => 'Paris', 'profession' => 'Danseuse',
                'contact_infos' => [['type' => 'phone', 'value' => '06 11 11 11 11'], ['type' => 'mail', 'value' => 'Anna@Test.io']]],
            '/api/employee/102/' => ['pk' => 102, 'first_name' => 'Yann', 'last_name' => 'Le Bras', 'city' => 'Rennes', 'profession' => 'Technicien', 'contact_infos' => []],
            '/api/planning_event/?project=12' => $page([
                ['pk' => 1, 'title' => 'Montage plateau', 'activity_type' => 'technical', 'start_datetime' => $dt('2026-12-01T09:00:00'), 'end_datetime' => $dt('2026-12-01T13:00:00')],
                ['pk' => 2, 'title' => '', 'activity_type' => 'performance', 'start_datetime' => $dt('2026-12-02T20:30:00'), 'end_datetime' => $dt('2026-12-02T22:00:00')],
                ['pk' => 3, 'title' => 'Représentation scolaire', 'activity_type' => 'performance', 'start_datetime' => $dt('2026-12-03T10:00:00'), 'end_datetime' => $dt('2026-12-03T11:00:00')],
                ['pk' => 4, 'title' => 'Représentation annulée', 'activity_type' => 'performance', 'is_cancelled' => true, 'start_datetime' => $dt('2026-12-04T20:00:00')],
                ['pk' => 5, 'title' => 'Nuitées', 'activity_type' => 'accommodation', 'start_datetime' => $dt('2026-12-01T18:00:00'), 'end_datetime' => $dt('2026-12-04T10:00:00'),
                    'place' => ['name' => 'Hôtel de la Gare', 'address1' => '1 place de la Gare', 'zipcode' => '56100', 'city' => 'Lorient'],
                    'notes' => 'Petit-déjeuner 7h-10h', 'entities' => [['pk' => 101], ['pk' => 102]]],
                ['pk' => 6, 'title' => 'Train retour', 'activity_type' => 'journey', 'start_datetime' => $dt('2026-12-04T09:00:00'), 'end_datetime' => $dt('2026-12-04T12:00:00')],
            ]),
            '/api/project_linked_contact/?parent=12' => $page([['pk' => 1, 'structure_person' => ['person' => ['first_name' => 'Marie', 'last_name' => 'Accueil'],
                'title' => 'Chargée d\'accueil', 'contact_infos' => [['type' => 'phone', 'value' => '02 97 00 00 00']]]]]),
        ];
    }

    private function connect(int $cid, string $key = self::KEY): ?array
    {
        return $this->call('PUT', "/api/companies/$cid/orfeo", ['token' => $key]);
    }

    public function testConnectImportAndReimport(): void
    {
        $this->orfeoData();
        $this->login();
        [$cid] = $this->buildCompany('Cie Orfeo');

        $res = $this->connect($cid, 'mauvaise-cle-123');
        self::assertSame(400, $this->code());
        self::assertStringContainsString('Clé d\'API Orfeo refusée', $res['error']);
        $company = $this->connect($cid);
        self::assertSame(['connected' => true, 'organization' => 'Compagnie Oiseaux (Orfeo)'], $company['orfeo']);
        $stored = $this->em()->find(Company::class, $cid)->orfeoToken;
        self::assertStringNotContainsString(self::KEY, $stored, 'clé chiffrée en base');

        $res = $this->call('POST', "/api/companies/$cid/orfeo/import", ['from' => '2026-11-01', 'to' => '2027-01-31']);
        self::assertSame(200, $this->code(), json_encode($res));
        self::assertSame('orfeo', $res['source']);
        [$f] = $res['files'];
        self::assertSame(['Orfeo · Concert à Lorient', 'orfeo', 'Les oiseaux de passage'], [$f['file'], $f['source'], $f['show']['title']]);
        $e = $f['event'];
        self::assertSame(['Théâtre du Moulin', 'Lorient', '4 rue du Port, 56100 Lorient', '2026-12-02', '2026-12-03', 2, '2026-12-01', '2026-12-04', 'confirmed'],
            [$e['venue'], $e['city'], $e['address'], $e['firstDate'], $e['lastDate'], $e['performances'], $e['techArrival'], $e['departure'], $e['status']]);
        self::assertStringContainsString('Marie Accueil (Chargée d\'accueil) · 02 97 00 00 00', $e['venueContact']);
        self::assertSame(['Anna DURAND', 'Yann LE BRAS'], array_column($f['team'], 'name'));
        self::assertSame([true, false], array_column($f['team'], 'known'), 'Anna existe déjà dans l\'annuaire (DURAND Anna)');
        self::assertEqualsCanonicalizing(['orfeo_unnamed', 'orfeo_journeys'], array_column($f['warnings'], 'code'));
        self::assertSame(['include' => true, 'includeRoadmap' => true], $f['defaults']);
        self::assertSame([], $res['vocabulary']['function'], 'fonction sur la date (« Régisseur lumière ») prioritaire sur la profession (« Technicien »)');
        self::assertSame([], array_column($res['vocabulary']['schedule'], 'value'), 'Montage plateau, Représentation… reconnus');

        $done = $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [0 => ['include' => true, 'includeRoadmap' => true]]]);
        self::assertSame(200, $this->code(), json_encode($done));
        $ev = $this->call('GET', "/api/events/{$done['created'][0]['id']}");
        self::assertSame(['Les oiseaux de passage', 'Théâtre du Moulin', 'confirmed'], [$ev['showTitle'], $ev['venue'], $ev['status']]);
        self::assertSame([['2026-12-01', '9h - 13h', 'Montage plateau', ''], ['2026-12-02', '20h30 - 22h', 'Représentation', ''], ['2026-12-03', '10h - 11h', 'Représentation', 'scolaire']],
            array_map(fn ($s) => [$s['day'], $s['time'], $s['label'], $s['detail']], $ev['schedule']));
        self::assertSame(['Hôtel de la Gare'], array_column($ev['lodgings'], 'name'));
        self::assertSame([$ev['lodgings'][0]['id'], $ev['lodgings'][0]['id']], array_column($ev['participations'], 'lodgingId'));
        [$anna, $yann] = $ev['participations'];
        self::assertSame(['Danseuse', '06 11 11 11 11', 'anna@test.io'], [$anna['role'], $anna['person']['phone'], $anna['person']['email']]);
        self::assertSame(['LE BRAS', 'Régisseur lumière', 'À confirmer dans Orfeo', 'Rennes'], [$yann['person']['lastName'], $yann['role'], $yann['note'], $yann['departureCity']]);
        self::assertNotEmpty($anna['days'], 'présence pré-remplie sur la période');
        self::assertSame(['Planning'], array_column($ev['sections'], 'title'));
        self::assertSame(12, $this->em()->find(Event::class, $ev['id'])->orfeoId);
        self::assertSame(101, $this->em()->find(Person::class, $anna['person']['id'])->orfeoId);

        // Nouvelle récupération : la date est reconnue (même si le lieu a changé dans Orfeo) et décochée ;
        // une personne renommée dans Orfeo est retrouvée par son identifiant
        $this->fake()->orfeo['/api/project/12/']['venue']['name'] = 'Salle du Moulin';
        $this->fake()->orfeo['/api/employee/101/']['last_name'] = 'Durand-Martin';
        $res = $this->call('POST', "/api/companies/$cid/orfeo/import", ['from' => '2026-11-01', 'to' => '2027-01-31']);
        self::assertContains('exists', array_column($res['files'][0]['warnings'], 'code'));
        self::assertFalse($res['files'][0]['defaults']['include']);
        self::assertSame([true, true], array_column($res['files'][0]['team'], 'known'));
    }

    public function testRightsErrorsAndDisconnect(): void
    {
        $this->orfeoData();
        $this->login();
        [$cid] = $this->buildCompany('Cie Orfeo');
        $this->call('POST', "/api/companies/$cid/orfeo/import", []);
        self::assertSame(400, $this->code(), 'pas encore reliée');
        self::assertFalse($this->call('GET', "/api/companies/$cid")['orfeo']['connected']);
        $this->connect($cid);

        // Orfeo en panne pour une date : la date est signalée, l'analyse continue
        $this->fake()->orfeo['/api/project/']['results'][] = ['pk' => 13, 'title' => 'Date fantôme'];
        $res = $this->call('POST', "/api/companies/$cid/orfeo/import", ['from' => '2026-11-01', 'to' => '2027-01-31']);
        self::assertSame('Élément introuvable dans Orfeo.', $res['files'][1]['error']);

        $this->call('POST', "/api/companies/$cid/orfeo/import", ['from' => '2027-01-31', 'to' => '2026-11-01']);
        self::assertSame(400, $this->code());

        // Clé révoquée dans Orfeo : message explicite
        $this->fake()->orfeoToken = 'nouvelle-cle-orfeo';
        $res = $this->call('POST', "/api/companies/$cid/orfeo/import", []);
        self::assertSame(400, $this->code());
        self::assertStringContainsString('Clé d\'API Orfeo refusée', $res['error']);

        // Seuls les gestionnaires de la compagnie relient ou délient Orfeo
        $this->call('POST', '/api/logout');
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->grant(['ed@test.io'], ['company.view', 'events.edit', 'people.edit'], [$cid]);
        $this->login('ed@test.io', 'ed-password-12345');
        $this->connect($cid);
        self::assertSame(403, $this->code());
        $this->call('DELETE', "/api/companies/$cid/orfeo");
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/logout');
        $this->login();
        self::assertFalse($this->call('DELETE', "/api/companies/$cid/orfeo")['orfeo']['connected']);
        self::assertNull($this->em()->find(Company::class, $cid)->orfeoToken);
    }
}
