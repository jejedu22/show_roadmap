<?php

namespace App\Tests;

class ApiTest extends ApiTestCase
{
    public function testAuthenticationAndCsrf(): void
    {
        $this->call('GET', '/api/companies');
        self::assertSame(401, $this->code());
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'admin-password-123'], csrf: false);
        self::assertSame(403, $this->code(), 'écriture sans X-Requested-With refusée');
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'mauvais']);
        self::assertSame(401, $this->code());
        $this->login();
        self::assertSame('admin@test.io', $this->call('GET', '/api/config')['user']['email']);
        $this->call('POST', '/api/logout');
        $this->call('GET', '/api/companies');
        self::assertSame(401, $this->code());
    }

    public function testLoginThrottling(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'mauvais']);
        }
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'admin-password-123']);
        self::assertSame(429, $this->code());
    }

    public function testOverlapsQuoteAndDocuments(): void
    {
        $this->login();
        [$cid, [$a, $b]] = $this->buildCompany();
        $e1 = $this->call('POST', "/api/shows/$a/events", ['venue' => 'Théâtre X', 'city' => 'Beauvais', 'firstDate' => '2026-05-19', 'lastDate' => '2026-05-21']);
        $e2 = $this->call('POST', "/api/shows/$b/events", ['venue' => 'Salle Y', 'firstDate' => '2026-05-20']);
        $people = array_column($e1['availablePeople'], 'id');
        self::assertCount(2, $people);
        $e1 = $this->call('POST', "/api/events/{$e1['id']}/participations", ['personIds' => $people]);
        $this->call('POST', "/api/events/{$e2['id']}/participations", ['personIds' => [$people[0]]]);

        // même personne sur deux dates simultanées → signalée
        $e1 = $this->call('GET', "/api/events/{$e1['id']}");
        self::assertSame('Spectacle B', $e1['participations'][0]['conflicts'][0]['showTitle']);
        self::assertSame([], $e1['participations'][1]['conflicts']);
        self::assertCount(1, $this->call('GET', "/api/companies/$cid")['events'][0]['overlaps']);

        // devis : billet de 100 €, cachet 1000, camion 100 km × 1,1
        $pid = $e1['participations'][0]['id'];
        $this->call('POST', "/api/events/{$e1['id']}/items/leg", ['participationId' => $pid, 'day' => '2026-05-19', 'price' => '100,00', 'origin' => 'Paris']);
        $this->call('PUT', "/api/events/{$e1['id']}/rates", ['fee' => '1000', 'mealRate' => '20,7', 'breakfastRate' => '0', 'truckRate' => '1,1', 'vatRate' => '5,5']);
        $ev = $this->call('POST', "/api/events/{$e1['id']}/items/truck", ['label' => 'A/R', 'km' => '100']);
        $q = $ev['quote'];
        self::assertGreaterThan(0, $q['mealsCount']);
        self::assertEquals(100, $q['travelTickets']);
        self::assertEqualsWithDelta(1000 + 110 + 100 + $q['mealsCount'] * 20.7, $q['totalHt'], 0.001);
        self::assertEqualsWithDelta(round($q['totalHt'] * 1.055, 2), $q['totalTtc'], 0.001);

        // grilles : modification puis réinitialisation
        $date = $ev['dates'][0];
        $ev = $this->call('PUT', "/api/events/{$ev['id']}/days", ['days' => [$pid => [$date => ['night' => 1, 'meals' => 2, 'breakfast' => 1]]]]);
        self::assertSame(['night' => 1, 'meals' => 2, 'breakfast' => 1], $ev['participations'][0]['days'][$date]);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/days/reset");
        self::assertSame(200, $this->code());

        $this->client->request('GET', "/api/events/{$ev['id']}/export.xlsx");
        self::assertSame(200, $this->code());
        self::assertStringStartsWith('PK', (string) $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString("filename*=utf-8''Spectacle_A_Th%C3%A9%C3%A2tre_X.xlsx", $this->client->getResponse()->headers->get('content-disposition'));

        $copy = $this->call('POST', "/api/events/{$ev['id']}/duplicate");
        self::assertCount(2, $copy['participations']);
        self::assertSame([], $copy['participations'][0]['legs']);
    }

    public function testFormulaInjectionInExport(): void
    {
        $this->login();
        [, [$a]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$a/events", ['venue' => 'V', 'firstDate' => '2026-06-01']);
        $this->call('POST', "/api/events/{$ev['id']}/items/extra", ['label' => '=HYPERLINK("http://x")', 'amount' => '1']);
        $people = array_column($ev['availablePeople'], 'id');
        $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => $people]);
        $this->client->request('GET', "/api/events/{$ev['id']}/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        foreach ($book->getAllSheets() as $ws) {
            foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
                self::assertFalse($ws->getCell($coord)->isFormula(), "$coord ne doit pas être une formule");
            }
        }
        unlink($file);
    }

    public function testTenantIsolation(): void
    {
        $this->login();
        [$cid, [$a]] = $this->buildCompany('Cie Privée');
        $ev = $this->call('POST', "/api/shows/$a/events", ['venue' => 'V', 'firstDate' => '2026-06-01']);
        $users = $this->call('POST', '/api/admin/users', ['email' => 'bob@test.io', 'name' => 'Bob', 'password' => 'bob-password-123']);
        $bobId = array_values(array_filter($users['users'], fn ($u) => 'bob@test.io' === $u['email']))[0]['id'];
        $this->call('POST', '/api/logout');

        $this->login('bob@test.io', 'bob-password-123');
        foreach ([['GET', "/api/events/{$ev['id']}"], ['GET', "/api/companies/$cid"], ['GET', "/api/events/{$ev['id']}/export.xlsx"],
            ['DELETE', "/api/events/{$ev['id']}"], ['POST', "/api/companies/$cid/people"], ['GET', "/api/events/{$ev['id']}/sncf/places?q=paris"]] as [$m, $u]) {
            $this->call($m, $u, []);
            self::assertSame(404, $this->code(), "$m $u");
        }
        self::assertSame([], $this->call('GET', '/api/companies'));
        $this->call('GET', '/api/admin/users');
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/logout');

        $this->login();
        $this->call('PUT', "/api/admin/users/$bobId", ['companyIds' => [$cid], 'isActive' => true]);
        $this->call('POST', '/api/logout');
        $this->login('bob@test.io', 'bob-password-123');
        $this->call('GET', "/api/events/{$ev['id']}");
        self::assertSame(200, $this->code());
    }

    public function testDeactivatedUserLosesSession(): void
    {
        $this->createUser('carl@test.io', 'carl-password-123');
        $this->login('carl@test.io', 'carl-password-123');
        $carl = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'carl@test.io']);
        $carl->isActive = false;
        $this->em()->flush();
        $this->call('GET', '/api/companies');
        self::assertSame(401, $this->code());
        $this->call('POST', '/api/login', ['email' => 'carl@test.io', 'password' => 'carl-password-123']);
        self::assertSame(401, $this->code());
    }

    public function testGoogleLogin(): void
    {
        $this->createUser('gina@test.io', 'gina-password-123');
        $this->client->request('GET', '/api/auth/google');
        $location = $this->client->getResponse()->headers->get('location');
        self::assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        self::assertSame('https://roadmap.test/api/auth/google/callback', $q['redirect_uri']);

        $this->client->request('GET', '/api/auth/google/callback?code=x&state=faux');
        self::assertStringStartsWith('/login?error=', $this->client->getResponse()->headers->get('location'));

        $this->client->request('GET', '/api/auth/google');
        parse_str(parse_url($this->client->getResponse()->headers->get('location'), PHP_URL_QUERY), $q);
        $this->client->request('GET', '/api/auth/google/callback?code=x&state='.$q['state']);
        self::assertSame('/', $this->client->getResponse()->headers->get('location'));
        self::assertSame('gina@test.io', $this->call('GET', '/api/config')['user']['email']);
    }

    public function testGoogleRejectsUnknownOrUnverified(): void
    {
        $this->createUser('gina@test.io', 'gina-password-123');
        foreach ([['email' => 'gina@test.io', 'email_verified' => false], ['email' => 'inconnu@test.io', 'email_verified' => true]] as $ident) {
            $this->client->disableReboot();
            static::getContainer()->get(FakeHttp::class)->googleUser = $ident;
            $this->client->request('GET', '/api/auth/google');
            parse_str(parse_url($this->client->getResponse()->headers->get('location'), PHP_URL_QUERY), $q);
            $this->client->request('GET', '/api/auth/google/callback?code=x&state='.$q['state']);
            self::assertStringContainsString('Aucun%20compte', $this->client->getResponse()->headers->get('location'));
            self::assertNull($this->call('GET', '/api/config')['user']);
        }
    }

    public function testExportListsOutboundThenReturnInTimeOrder(): void
    {
        $this->login();
        [, [$a]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$a/events", ['venue' => 'V', 'firstDate' => '2026-06-02']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $pid = $ev['participations'][0]['id'];
        foreach ([['return', '2026-06-03', '18h00'], ['outbound', '2026-06-01', '10h00'], ['outbound', '2026-06-01', '9h05'], ['outbound', '2026-05-31', '']] as [$dir, $day, $time]) {
            $this->call('POST', "/api/events/{$ev['id']}/items/leg", ['participationId' => $pid, 'direction' => $dir, 'day' => $day, 'depTime' => $time]);
        }
        $this->client->request('GET', "/api/events/{$ev['id']}/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetByName('Voyages')->toArray();
        unlink($file);
        self::assertSame([['Aller', '31/05/2026', ''], ['Aller', '01/06/2026', '9h05'], ['Aller', '01/06/2026', '10h00'], ['Retour', '03/06/2026', '18h00']],
            array_map(fn ($r) => [$r[1], $r[2], (string) $r[5]], array_slice($rows, 1)));
    }

    public function testPdfDocumentsWithBookingLinks(): void
    {
        $this->login();
        [$cid, [$a]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$a/events", ['venue' => 'Théâtre X', 'firstDate' => '2026-06-02']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $pid = $ev['participations'][0]['id'];
        $leg = ['participationId' => $pid, 'day' => '2026-06-01', 'origin' => 'Paris', 'destination' => 'Beauvais'];

        // lien de réservation : http(s) uniquement
        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://x.fr/b', 'https://exemple.fr/"><script>'] as $bad) {
            $this->call('POST', "/api/events/{$ev['id']}/items/leg", $leg + ['bookingUrl' => $bad]);
            self::assertSame(400, $this->code(), $bad);
        }
        $ev = $this->call('POST', "/api/events/{$ev['id']}/items/leg", $leg + ['bookingUrl' => 'https://www.sncf-connect.com/app/trips/ABC']);
        self::assertSame('https://www.sncf-connect.com/app/trips/ABC', $ev['participations'][0]['legs'][0]['bookingUrl']);

        foreach (['roadmap', 'rooming', 'perdiem', 'travelcost', 'contacts', 'quote'] as $doc) {
            $this->client->request('GET', "/api/events/{$ev['id']}/pdf/$doc");
            $res = $this->client->getResponse();
            self::assertSame(200, $res->getStatusCode(), $doc);
            self::assertSame('application/pdf', $res->headers->get('content-type'));
            self::assertStringStartsWith('inline;', $res->headers->get('content-disposition'));
            $pdf = (string) $this->client->getInternalResponse()->getContent();
            self::assertStringStartsWith('%PDF-', $pdf);
            if (in_array($doc, ['roadmap', 'travelcost'], true)) {
                self::assertStringContainsString('/URI (https://www.sncf-connect.com/app/trips/ABC)', $pdf, "lien cliquable dans $doc");
            }
        }
        $this->client->request('GET', "/api/events/{$ev['id']}/pdf/roadmap?download=1");
        self::assertStringStartsWith('attachment;', $this->client->getResponse()->headers->get('content-disposition'));
        $this->call('GET', "/api/events/{$ev['id']}/pdf/inconnu");
        self::assertSame(404, $this->code());

        // cloisonnement
        $this->call('POST', '/api/admin/users', ['email' => 'eve@test.io', 'password' => 'eve-password-123']);
        $this->call('POST', '/api/logout');
        $this->login('eve@test.io', 'eve-password-123');
        $this->call('GET', "/api/events/{$ev['id']}/pdf/roadmap");
        self::assertSame(404, $this->code());
    }

    public function testCreateRenameAndDeleteCompanies(): void
    {
        $this->createUser('zoe@test.io', 'zoe-password-123');
        $this->login('zoe@test.io', 'zoe-password-123');
        [$c1] = $this->buildCompany('Cie Une');
        $c2 = $this->call('POST', '/api/companies', ['name' => 'Cie Deux'])['id'];
        self::assertSame(['Cie Deux', 'Cie Une'], array_column($this->call('GET', '/api/companies'), 'name'), 'le créateur accède à ses compagnies');
        self::assertSame('Cie 2', $this->call('PUT', "/api/companies/$c2", ['name' => 'Cie 2', 'notes' => 'n'])['name']);
        $this->call('DELETE', "/api/companies/$c1", ['confirmName' => 'Cie Une']);
        self::assertSame(403, $this->code(), 'suppression réservée aux administrateurs');
        $this->call('POST', '/api/logout');

        $this->login();
        $this->call('DELETE', "/api/companies/$c1", ['confirmName' => 'cie une']);
        self::assertSame(400, $this->code(), 'nom exact exigé');
        $this->call('DELETE', "/api/companies/$c1", ['confirmName' => 'Cie Une']);
        self::assertSame(200, $this->code());
        $this->call('GET', "/api/companies/$c1");
        self::assertSame(404, $this->code());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM person p JOIN company c ON c.id = p.company_id WHERE c.name = ?', ['Cie Une']));
        self::assertSame(['Cie 2'], array_column($this->call('GET', '/api/companies'), 'name'));
    }
}
