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
