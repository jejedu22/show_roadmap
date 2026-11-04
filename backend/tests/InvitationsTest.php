<?php

namespace App\Tests;

/** Invitations : contingent du lieu (date ou représentations), invités Cie et Pro, absence de la feuille de route et de l'avancement. */
class InvitationsTest extends ApiTestCase
{
    public function testInvitationsByPerformance(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        $total = $ev['readiness']['total'];
        $steps = array_column($ev['readiness']['steps'], null, 'key');
        self::assertSame('Facultatif', $steps['invitations']['detail']);

        // Sans représentation au planning : contingent pour toute la date
        $ev = $this->call('PUT', "/api/events/$id/invitation-quotas", ['total' => 10]);
        self::assertSame(10, $ev['invitationSummary']['quota']);
        $url = "/api/events/$id/items/invitation";
        $this->call('POST', $url, ['kind' => 'pro', 'firstName' => 'Zoé']);
        self::assertSame(400, $this->code(), 'nom obligatoire');
        $ev = $this->call('POST', $url, ['kind' => 'pro', 'lastName' => 'Moreau', 'firstName' => 'Zoé', 'structure' => 'Festival d\'Avignon', 'places' => 2]);
        $ev = $this->call('POST', $url, ['kind' => 'company', 'lastName' => 'Durand', 'firstName' => 'Paul', 'structure' => 'ignorée']);
        self::assertSame([3, 1, 2, 7], [$ev['invitationSummary']['used'], $ev['invitationSummary']['company'], $ev['invitationSummary']['pro'], $ev['invitationSummary']['left']]);
        self::assertSame('', array_column($ev['invitations'], null, 'lastName')['Durand']['structure'], 'pas de structure pour la Cie');
        self::assertSame($total, $ev['readiness']['total'], 'étape facultative, hors avancement');
        self::assertTrue(array_column($ev['readiness']['steps'], null, 'key')['invitations']['done']);

        // Représentations du planning : contingent par représentation, invitations réparties
        $ev = $this->call('POST', "/api/events/$id/items/schedule", ['day' => '2027-01-19', 'time' => '20h', 'label' => 'Représentation', 'detail' => '#1']);
        $ev = $this->call('POST', "/api/events/$id/items/schedule", ['day' => '2027-01-20', 'time' => '15h', 'label' => 'Représentation', 'detail' => '#2']);
        $ev = $this->call('POST', "/api/events/$id/items/schedule", ['day' => '2027-01-19', 'time' => '14h', 'label' => 'Montage']);
        [$p1, $p2] = array_column($ev['invitationPerformances'], 'id');
        self::assertCount(2, $ev['invitationPerformances'], 'seules les représentations');
        $ev = $this->call('PUT', "/api/events/$id/invitation-quotas", ['performances' => [$p1 => 6, $p2 => 4]]);
        self::assertSame(10, $ev['invitationSummary']['quota'], 'somme des représentations');
        self::assertSame(3, $ev['invitationSummary']['byPerformance']['none']['company'] + $ev['invitationSummary']['byPerformance']['none']['pro'], 'à répartir');
        $zoe = array_column($ev['invitations'], null, 'lastName')['Moreau'];
        $ev = $this->call('PUT', "$url/{$zoe['id']}", ['kind' => 'pro', 'lastName' => 'Moreau', 'firstName' => 'Zoé', 'structure' => 'Festival', 'places' => 2, 'performanceId' => $p1]);
        self::assertSame(4, $ev['invitationSummary']['byPerformance'][$p1]['left']);
        $montage = array_values(array_filter($ev['schedule'], fn ($s) => 'Montage' === $s['label']))[0]['id'];
        $this->call('PUT', "$url/{$zoe['id']}", ['kind' => 'pro', 'lastName' => 'Moreau', 'performanceId' => $montage]);
        self::assertSame(400, $this->code(), 'une ligne de planning qui n\'est pas une représentation');

        // Représentation supprimée : l'invitation reste, à répartir
        $ev = $this->call('DELETE', "/api/events/$id/items/schedule/$p1");
        self::assertNull(array_column($ev['invitations'], null, 'lastName')['Moreau']['performanceId']);
        self::assertCount(2, $ev['invitations']);

        $ev = $this->call('DELETE', "$url/{$zoe['id']}");
        self::assertCount(1, $ev['invitations']);

        // Liste PDF : visible avec « Consulter »
        $this->client->request('GET', "/api/events/$id/pdf/invitations");
        self::assertSame(200, $this->code());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('content-type'));
    }
}
