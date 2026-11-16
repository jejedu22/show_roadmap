<?php

namespace App\Tests;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Détaxes : contingent prévu (date ou représentation), comme les invitations ; chaque personne est une invitation ou une détaxe. */
class DetaxesTest extends ApiTestCase
{
    public function testDetaxes(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        $url = "/api/events/$id/items/invitation";

        // Toute la date : 10 invitations, 4 détaxes
        $ev = $this->call('PUT', "/api/events/$id/invitation-quotas", ['total' => 10, 'detaxeTotal' => 4]);
        self::assertSame([10, 4], [$ev['invitationSummary']['quota'], $ev['invitationSummary']['detaxeQuota']]);
        $this->call('POST', $url, ['kind' => 'pro', 'ticket' => 'detaxe', 'lastName' => 'Moreau', 'structure' => 'Festival', 'places' => 3]);
        $ev = $this->call('POST', $url, ['kind' => 'company', 'lastName' => 'Durand', 'places' => 2]);
        $s = $ev['invitationSummary'];
        self::assertSame([2, 8, 3, 1], [$s['used'], $s['left'], $s['detaxe'], $s['detaxeLeft']], 'chacun sur son contingent');
        self::assertSame(['detaxe', 'invitation'], [array_column($ev['invitations'], null, 'lastName')['Moreau']['ticket'], array_column($ev['invitations'], null, 'lastName')['Durand']['ticket']]);
        self::assertStringContainsString('3 / 4 détaxes', array_column($ev['readiness']['steps'], null, 'key')['invitations']['detail']);

        // Changer une détaxe en invitation
        $moreau = array_column($ev['invitations'], null, 'lastName')['Moreau'];
        $ev = $this->call('PUT', "$url/{$moreau['id']}", ['kind' => 'pro', 'ticket' => 'invitation', 'lastName' => 'Moreau', 'structure' => 'Festival', 'places' => 3]);
        self::assertSame([5, 0], [$ev['invitationSummary']['used'], $ev['invitationSummary']['detaxe']]);
        $this->call('PUT', "$url/{$moreau['id']}", ['kind' => 'pro', 'ticket' => 'detaxe', 'lastName' => 'Moreau', 'structure' => 'Festival', 'places' => 3]);

        // Par représentation
        $ev = $this->call('POST', "/api/events/$id/items/schedule", ['day' => '2027-01-19', 'time' => '20h', 'label' => 'Représentation']);
        $p1 = $ev['invitationPerformances'][0]['id'];
        $ev = $this->call('PUT', "/api/events/$id/invitation-quotas", ['performances' => [$p1 => ['invitations' => 6, 'detaxes' => 2]]]);
        self::assertSame([6, 2], [$ev['invitationPerformances'][0]['quota'], $ev['invitationPerformances'][0]['detaxeQuota']]);
        $ev = $this->call('PUT', "$url/{$moreau['id']}", ['kind' => 'pro', 'ticket' => 'detaxe', 'lastName' => 'Moreau', 'places' => 3, 'performanceId' => $p1]);
        self::assertSame([2, 3, -1], [$ev['invitationSummary']['byPerformance'][$p1]['detaxeQuota'], $ev['invitationSummary']['byPerformance'][$p1]['detaxe'], $ev['invitationSummary']['byPerformance'][$p1]['detaxeLeft']], 'dépassement');

        // PDF et Excel : type de billet
        $this->client->request('GET', "/api/events/$id/pdf/invitations");
        self::assertSame(200, $this->code());
        $this->client->request('GET', "/api/events/$id/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = IOFactory::load($file)->getSheetByName('Invitations')->toArray(null, true, false);
        unlink($file);
        $moreauRow = array_values(array_filter($rows, fn ($r) => 'Moreau' === $r[4]))[0];
        self::assertSame(['Détaxe', 'Pro'], [$moreauRow[2], $moreauRow[3]]);
    }
}
