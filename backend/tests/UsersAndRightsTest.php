<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\User;

/** Droits par groupe, filtrage des données, invitations, réinitialisations et garde-fous d'administration. */
class UsersAndRightsTest extends ApiTestCase
{
    private const STRONG = 'Valise-Rouge-Tournee-2027';

    /** Compagnie avec une date, une équipe et un trajet payant ; retourne [companyId, eventId, legId]. */
    private function fixture(): array
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany('Cie Droits');
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $this->call('PUT', "/api/people/{$ev['participations'][0]['person']['id']}", ['lastName' => 'DURAND', 'firstName' => 'Anna', 'phone' => '06 11 22 33 44', 'email' => 'anna@x.fr']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/items/leg", ['participationId' => $ev['participations'][0]['id'], 'day' => '2027-01-18', 'price' => '42']);
        $this->call('PUT', "/api/events/{$ev['id']}/rates", ['fee' => '1000', 'mealRate' => '20', 'vatRate' => '5.5']);
        $this->call('POST', '/api/logout');

        return [$cid, $ev['id'], $ev['participations'][0]['legs'][0]['id']];
    }

    public function testReadOnlyGroupSeesNoMoneyNorContacts(): void
    {
        [$cid, $ev] = $this->fixture();
        $this->createUser('lea@test.io', 'lea-password-123');
        $this->grant(['lea@test.io'], ['company.view'], [$cid]);
        $this->login('lea@test.io', 'lea-password-123');

        self::assertSame(['Cie Droits'], array_column($this->call('GET', '/api/companies'), 'name'));
        $data = $this->call('GET', "/api/events/$ev");
        self::assertSame(['company.view'], $data['permissions']);
        self::assertArrayNotHasKey('quote', $data);
        self::assertArrayNotHasKey('rates', $data);
        self::assertArrayNotHasKey('personCosts', $data);
        self::assertNull($data['participations'][0]['legs'][0]['price']);
        self::assertSame('', $data['participations'][0]['person']['phone']);
        self::assertSame('', $data['participations'][0]['person']['email']);
        self::assertNotContains('quote', array_column($data['readiness']['steps'], 'key'));
        self::assertSame('', $this->call('GET', "/api/companies/$cid")['people'][0]['phone']);

        foreach ([['PUT', "/api/events/$ev"], ['POST', "/api/events/$ev/items/leg"], ['PUT', "/api/events/$ev/rates"], ['POST', "/api/events/$ev/share"],
            ['POST', "/api/companies/$cid/people"], ['GET', "/api/events/$ev/export.xlsx"], ['GET', "/api/events/$ev/pdf/quote"],
            ['GET', "/api/events/$ev/pdf/contacts"], ['GET', "/api/events/$ev/sncf/places?q=paris"], ['PUT', "/api/companies/$cid"],
            ['GET', '/api/admin/users'], ['GET', '/api/admin/groups'], ['GET', '/api/admin/audit'], ['POST', '/api/companies']] as [$m, $u]) {
            $res = $this->call($m, $u, []);
            self::assertSame(403, $this->code(), "$m $u");
            self::assertStringContainsString('Droit nécessaire', $res['error']);
        }
        $this->client->request('GET', "/api/events/$ev/pdf/roadmap");
        self::assertSame(200, $this->code());
    }

    public function testStageManagerGroup(): void
    {
        [$cid, $ev, $leg] = $this->fixture();
        $this->createUser('max@test.io', 'max-password-123');
        $this->grant(['max@test.io'], ['company.view', 'events.edit', 'travel.edit', 'people.view'], [$cid]);
        $elsewhere = new \App\Entity\Company('Ailleurs');
        $this->em()->persist($elsewhere);
        $this->em()->flush();
        $this->grant(['max@test.io'], ['finance.edit'], [$elsewhere->id]); // droit sur une autre compagnie : sans effet ici
        $this->login('max@test.io', 'max-password-123');
        $data = $this->call('PUT', "/api/events/$ev/items/leg/$leg", ['day' => '2027-01-18', 'origin' => 'Lyon', 'price' => '99']);
        self::assertSame(200, $this->code());
        self::assertSame('Lyon', $data['participations'][0]['legs'][0]['origin']);
        self::assertSame('06 11 22 33 44', $data['participations'][0]['person']['phone']);
        $this->call('PUT', "/api/events/$ev/rates", ['fee' => '1']);
        self::assertSame(403, $this->code());
        $this->call('POST', "/api/events/$ev/items/extra", ['label' => 'x', 'amount' => '1']);
        self::assertSame(403, $this->code());
    }

    public function testFinanceEditImpliesFinanceView(): void
    {
        [$cid, $ev] = $this->fixture();
        $this->createUser('fin@test.io', 'fin-password-123');
        $this->grant(['fin@test.io'], ['finance.edit'], [$cid]);
        $this->login('fin@test.io', 'fin-password-123');
        $data = $this->call('GET', "/api/events/$ev");
        self::assertContains('finance.view', $data['permissions']);
        self::assertContains('company.view', $data['permissions']);
        self::assertEquals(1000, $data['quote']['fee']);
    }

    public function testInvitationAndResetLinks(): void
    {
        [$cid] = $this->fixture();
        $this->login();
        $groupId = $this->grant([], ['company.view'], [$cid], name: 'Lecture Cie Droits')->id;
        $res = $this->call('POST', '/api/admin/users', ['email' => 'Nina@Test.io', 'name' => 'Nina', 'groupIds' => [$groupId]]);
        self::assertSame('invite', $res['linkType']);
        $token = basename($res['link']);
        self::assertStringContainsString('/mot-de-passe/', $res['link']);
        $nina = array_values(array_filter($res['users'], fn ($u) => 'nina@test.io' === $u['email']))[0];
        self::assertNotNull($nina['invitationExpiresAt']);
        self::assertSame([$groupId], $nina['groupIds']);
        $this->call('POST', '/api/admin/users', ['email' => 'nina@test.io']);
        self::assertSame(409, $this->code(), 'adresse déjà utilisée');
        $this->call('POST', '/api/logout');

        // le jeton n'est stocké qu'en empreinte
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM user_token WHERE hash = ?', [$token]));
        self::assertSame('nina@test.io', $this->call('GET', "/api/auth/token/$token")['email']);
        foreach (['court', 'Tournee2026!', 'nina-test-password-1234'] as $weak) {
            $this->call('POST', "/api/auth/token/$token", ['password' => $weak]);
            self::assertSame(400, $this->code(), $weak);
        }
        $this->call('POST', "/api/auth/token/$token", ['password' => self::STRONG]);
        self::assertSame(200, $this->code());
        self::assertSame(['Cie Droits'], array_column($this->call('GET', '/api/companies'), 'name'), 'connectée après activation');
        $this->call('GET', "/api/auth/token/$token");
        self::assertSame(404, $this->code(), 'lien à usage unique');
        $this->call('POST', '/api/logout');
        $this->login('nina@test.io', self::STRONG);
        $this->call('POST', '/api/logout');

        // réinitialisation : nouveau lien, l'ancien mot de passe cesse de fonctionner une fois le nouveau choisi
        $this->login();
        $id = $this->em()->getRepository(User::class)->findOneBy(['email' => 'nina@test.io'])->id;
        $res = $this->call('POST', "/api/admin/users/$id/link");
        self::assertSame('reset', $res['linkType']);
        $this->call('POST', '/api/logout');
        $this->call('POST', '/api/auth/token/'.basename($res['link']), ['password' => 'Autre-Phrase-Secrete-2027']);
        self::assertSame(200, $this->code());
        $this->call('POST', '/api/logout');
        $this->call('POST', '/api/login', ['email' => 'nina@test.io', 'password' => self::STRONG]);
        self::assertSame(401, $this->code());
        $this->call('GET', '/api/auth/token/'.str_repeat('0', 64));
        self::assertSame(404, $this->code());
    }

    public function testDelegatedAdminCannotEscalate(): void
    {
        [$cid] = $this->fixture();
        $this->login();
        $other = $this->call('POST', '/api/companies', ['name' => 'Cie Secrète'])['id'];
        $this->call('POST', '/api/logout');
        $this->createUser('rh@test.io', 'rh-password-1234');
        $this->grant(['rh@test.io'], ['users.manage', 'groups.manage'], name: 'RH');
        $this->grant(['rh@test.io'], ['company.view'], [$cid]);
        $admins = $this->em()->getRepository(\App\Entity\Group::class)->findOneBy(['name' => 'RH']);
        $rh = $this->em()->getRepository(User::class)->findOneBy(['email' => 'rh@test.io']);
        $this->login('rh@test.io', 'rh-password-1234');

        $this->call('PUT', "/api/admin/users/{$rh->id}", ['isAdmin' => true]);
        self::assertSame(403, $this->code(), 'pas d\'auto-promotion super-admin');
        $this->call('POST', '/api/admin/groups', ['name' => 'Moi', 'permissions' => ['users.manage'], 'userIds' => [$rh->id]]);
        self::assertSame(403, $this->code(), 'droits d\'administration réservés');
        $this->call('POST', '/api/admin/groups', ['name' => 'Tout', 'permissions' => ['company.view'], 'allCompanies' => true, 'userIds' => [$rh->id]]);
        self::assertSame(403, $this->code(), 'toutes les compagnies réservé');
        $this->call('POST', '/api/admin/groups', ['name' => 'Secret', 'permissions' => ['company.view'], 'companyIds' => [$other], 'userIds' => [$rh->id]]);
        self::assertSame(403, $this->code(), 'compagnie invisible');
        $this->call('DELETE', "/api/admin/groups/{$admins->id}");
        self::assertSame(403, $this->code(), 'groupe d\'administration protégé');
        $super = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@test.io']);
        $this->call('PUT', "/api/admin/users/{$super->id}", ['isActive' => false]);
        self::assertSame(403, $this->code(), 'super-admin intouchable');
        $this->call('POST', "/api/admin/users/{$super->id}/link");
        self::assertSame(403, $this->code(), 'pas de lien de réinitialisation pour un super-admin');

        // ce qui reste permis : un groupe de droits « compagnie » sur ses propres compagnies
        $res = $this->call('POST', '/api/admin/groups', ['name' => 'Régie Cie Droits', 'permissions' => ['company.view', 'travel.edit', 'inventé'], 'companyIds' => [$cid]]);
        self::assertSame(200, $this->code());
        $g = array_values(array_filter($res['groups'], fn ($g) => 'Régie Cie Droits' === $g['name']))[0];
        self::assertSame(['company.view', 'travel.edit'], $g['permissions'], 'droits inconnus ignorés');
        self::assertNotEmpty($this->call('GET', '/api/admin/audit'));
    }

    public function testSuperAdminGuards(): void
    {
        $this->login();
        $me = $this->em()->getRepository(User::class)->findOneBy(['email' => 'admin@test.io']);
        $this->call('PUT', "/api/admin/users/{$me->id}", ['isActive' => false]);
        self::assertSame(400, $this->code());
        $this->call('DELETE', "/api/admin/users/{$me->id}");
        self::assertSame(400, $this->code());
        $this->call('PUT', "/api/admin/users/{$me->id}", ['isAdmin' => false]);
        self::assertSame(400, $this->code());
        // un deuxième super-admin peut être retiré, pas le dernier
        $this->createUser('boss@test.io', 'boss-password-12', true);
        $boss = $this->em()->getRepository(User::class)->findOneBy(['email' => 'boss@test.io']);
        $this->call('PUT', "/api/admin/users/{$boss->id}", ['isActive' => false]);
        self::assertSame(200, $this->code());
        $this->call('POST', '/api/logout');
        $this->call('POST', '/api/login', ['email' => 'boss@test.io', 'password' => 'boss-password-12']);
        self::assertSame(401, $this->code());
    }

    public function testPasswordChangeKeepsSessionAndIsAudited(): void
    {
        $this->login();
        $this->call('PUT', '/api/me', ['currentPassword' => 'faux', 'newPassword' => self::STRONG]);
        self::assertSame(400, $this->code());
        $this->call('PUT', '/api/me', ['currentPassword' => 'admin-password-123', 'newPassword' => 'motdepasse12']);
        self::assertSame(400, $this->code());
        $this->call('PUT', '/api/me', ['currentPassword' => 'admin-password-123', 'newPassword' => self::STRONG]);
        self::assertSame(200, $this->code());
        $this->call('GET', '/api/companies');
        self::assertSame(200, $this->code(), 'session courante conservée');
        $this->call('POST', '/api/logout');
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'mauvais']);
        $actions = array_map(fn (AuditLog $a) => $a->action, $this->em()->getRepository(AuditLog::class)->findAll());
        foreach (['login.success', 'user.password_change_failed', 'user.password_change', 'login.failure'] as $a) {
            self::assertContains($a, $actions);
        }
        $this->login('admin@test.io', self::STRONG);
        self::assertContains('users.manage', $this->call('GET', '/api/config')['user']['permissions']);
    }
}
