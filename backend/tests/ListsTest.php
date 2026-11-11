<?php

namespace App\Tests;

use App\Entity\AuditLog;

/** Listes paramétrées : administration, contrôle des saisies, valeurs « hors liste » conservées. */
class ListsTest extends ApiTestCase
{
    public function testAdminEditsListsAndEntriesAreChecked(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $lists = $this->call('GET', '/api/lists');
        self::assertContains('Danseuse', $lists['function']);
        self::assertContains('Représentation', $lists['schedule']);

        // Remplacement complet : ordre, doublons (casse, accents) et blancs écartés
        $res = $this->call('PUT', '/api/admin/lists/function', ['items' => ['Danseuse', ' Régisseur  lumière ', 'danseuse', '', 'Habilleuse']]);
        self::assertSame(['Danseuse', 'Régisseur lumière', 'Habilleuse'], $res['function']);
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'lists.update']));
        $this->call('PUT', '/api/admin/lists/inconnue', ['items' => []]);
        self::assertSame(404, $this->code());

        // Saisie : valeur de la liste (casse corrigée), vide, ou valeur hors liste inchangée
        $company = $this->call('GET', "/api/companies/$cid");
        $anna = $company['people'][0];
        self::assertSame('Danseuse', $anna['function']);
        $this->call('PUT', "/api/people/{$anna['id']}", ['lastName' => 'DURAND', 'function' => 'Chanteuse']);
        self::assertSame(400, $this->code());
        $this->call('PUT', "/api/people/{$anna['id']}", ['lastName' => 'DURAND', 'function' => 'habilleuse']);
        self::assertSame(200, $this->code());
        self::assertSame('Habilleuse', $this->call('GET', "/api/companies/$cid")['people'][0]['function'], 'casse de la liste');

        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/items/schedule", ['day' => '2027-01-19', 'time' => '20h', 'label' => 'Représentation', 'detail' => '#1 scolaire']);
        self::assertSame([['Représentation', '#1 scolaire']], array_map(fn ($s) => [$s['label'], $s['detail']], $ev['schedule']));
        $this->call('POST', "/api/events/{$ev['id']}/items/schedule", ['day' => '2027-01-19', 'label' => 'Apéro']);
        self::assertSame(400, $this->code());

        // Une entrée retirée de la liste reste sur les lignes existantes, qui peuvent encore être enregistrées
        $this->call('PUT', '/api/admin/lists/schedule', ['items' => ['Montage', 'Démontage']]);
        $slot = $ev['schedule'][0];
        $ev = $this->call('PUT', "/api/events/{$ev['id']}/items/schedule/{$slot['id']}", ['day' => '2027-01-19', 'time' => '19h', 'label' => 'Représentation', 'detail' => '#1']);
        self::assertSame(['19h', 'Représentation'], [$ev['schedule'][0]['time'], $ev['schedule'][0]['label']]);
    }

    public function testLegModesComeFromTheList(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        self::assertContains('TGV INOUI', $this->call('GET', '/api/lists')['mode']);
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $url = "/api/events/{$ev['id']}/items/leg";
        $leg = ['participationId' => $ev['participations'][0]['id'], 'day' => '2027-01-18'];

        $this->call('POST', $url, $leg + ['mode' => 'Trottinette']);
        self::assertSame(400, $this->code());
        $ev = $this->call('POST', $url, $leg + ['mode' => 'tgv inoui']);
        self::assertSame('TGV INOUI', $ev['participations'][0]['legs'][0]['mode'], 'casse de la liste');

        // Mode retiré de la liste : le trajet le garde et peut encore être enregistré
        $this->call('PUT', '/api/admin/lists/mode', ['items' => ['Avion', 'Covoiturage']]);
        $id = $ev['participations'][0]['legs'][0]['id'];
        $ev = $this->call('PUT', "$url/$id", $leg + ['mode' => 'TGV INOUI', 'origin' => 'Lyon']);
        self::assertSame(['TGV INOUI', 'Lyon'], [$ev['participations'][0]['legs'][0]['mode'], $ev['participations'][0]['legs'][0]['origin']]);
        $ev = $this->call('PUT', "$url/$id", $leg + ['mode' => 'Covoiturage']);
        self::assertSame('Covoiturage', $ev['participations'][0]['legs'][0]['mode']);
    }

    public function testTravelClassForModesWithClass(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        // « Classe » coché pour le TGV seulement ; une entrée sans précision garde sa case
        $res = $this->call('PUT', '/api/admin/lists/mode', ['items' => [['label' => 'TGV INOUI', 'withClass' => true], ['label' => 'Bus', 'withClass' => false], 'Avion']]);
        self::assertSame(['TGV INOUI'], $res['modeClass']);
        self::assertSame(['TGV INOUI'], $this->call('GET', '/api/lists')['modeClass']);

        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => [$ev['availablePeople'][0]['id']]]);
        $url = "/api/events/{$ev['id']}/items/leg";
        $leg = ['participationId' => $ev['participations'][0]['id'], 'day' => '2027-01-18'];
        $ev = $this->call('POST', $url, $leg + ['mode' => 'TGV INOUI', 'travelClass' => '1']);
        self::assertSame('1', $ev['participations'][0]['legs'][0]['travelClass']);
        $id = $ev['participations'][0]['legs'][0]['id'];
        $ev = $this->call('PUT', "$url/$id", $leg + ['mode' => 'TGV INOUI', 'travelClass' => '3']);
        self::assertSame('', $ev['participations'][0]['legs'][0]['travelClass'], 'seulement 1re ou 2nde');
        $ev = $this->call('PUT', "$url/$id", $leg + ['mode' => 'Bus', 'travelClass' => '2']);
        self::assertSame('', $ev['participations'][0]['legs'][0]['travelClass'], 'pas de classe pour un mode sans classe');
        $ev = $this->call('PUT', "$url/$id", $leg + ['mode' => 'TGV INOUI', 'travelClass' => '2']);

        // Export Excel : colonne Classe
        $this->client->request('GET', "/api/events/{$ev['id']}/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetByName('Voyages')->toArray(null, true, false);
        unlink($file);
        $header = $rows[array_search('Nom', array_column($rows, 0), true)];
        $first = $rows[array_search('Nom', array_column($rows, 0), true) + 1];
        self::assertSame('2nde', $first[array_search('Classe', $header, true)]);

        // Ne pas toucher à la case en réordonnant la liste sans la préciser
        self::assertSame(['TGV INOUI'], $this->call('PUT', '/api/admin/lists/mode', ['items' => ['Avion', 'TGV INOUI', 'Bus']])['modeClass']);
    }

    public function testOnlyListManagersEdit(): void
    {
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->login('ed@test.io', 'ed-password-12345');
        self::assertNotEmpty($this->call('GET', '/api/lists')['function'], 'lecture pour tout utilisateur connecté');
        $this->call('PUT', '/api/admin/lists/function', ['items' => ['X']]);
        self::assertSame(403, $this->code());

        $this->call('POST', '/api/logout');
        $this->login();
        $this->grant(['ed@test.io'], ['lists.manage']);
        $this->call('POST', '/api/logout');
        $this->login('ed@test.io', 'ed-password-12345');
        self::assertSame(['X'], $this->call('PUT', '/api/admin/lists/function', ['items' => ['X']])['function']);
    }
}
