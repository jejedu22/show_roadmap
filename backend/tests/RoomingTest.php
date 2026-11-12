<?php

namespace App\Tests;

/** Chambres double / twin partagées : lien dans les deux sens, rooming-list par chambre, nuitées du devis par chambre. */
class RoomingTest extends ApiTestCase
{
    public function testSharedRooms(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $this->call('POST', "/api/companies/$cid/people", ['lastName' => 'PETIT', 'firstName' => 'Zoé']);
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $id = $ev['id'];
        $this->call('PUT', "/api/events/$id/rates", ['nightRate' => '50', 'fee' => '1000']);
        $p = array_column(array_map(fn ($x) => ['n' => $x['person']['firstName'], 'id' => $x['id']], $ev['participations']), 'id', 'n');
        $row = fn ($n, $type, $mate = null) => ['id' => $p[$n], 'role' => '', 'roomType' => $type, 'roommateId' => $mate ? $p[$mate] : null];
        $nightsPerPerson = count(array_filter($ev['participations'][0]['days'], fn ($d) => $d['night']));

        // Anna partage une double avec Luc : lien dans les deux sens, Luc passe en double
        $ev = $this->call('PUT', "/api/events/$id/participations", ['participations' => [$row('Anna', 'double', 'Luc'), $row('Luc', 'single'), $row('Zoé', 'single')]]);
        $parts = array_column($ev['participations'], null, 'id');
        self::assertSame([$p['Luc'], $p['Anna'], 'double'], [$parts[$p['Anna']]['roommateId'], $parts[$p['Luc']]['roommateId'], $parts[$p['Luc']]['roomType']]);
        // Devis : 3 personnes, 2 chambres → nuitées par chambre
        self::assertSame(2 * $nightsPerPerson, $ev['quote']['nightsCount']);
        self::assertStringContainsString('par chambre', $ev['quote']['lines'][4]['label']);

        // Zoé ne peut pas prendre Luc, déjà en chambre avec Anna
        $ev = $this->call('PUT', "/api/events/$id/participations", ['participations' => [$row('Anna', 'double', 'Luc'), $row('Luc', 'double', 'Anna'), $row('Zoé', 'twin', 'Luc')]]);
        self::assertNull(array_column($ev['participations'], null, 'id')[$p['Zoé']]['roommateId']);

        // Rooming-list : une ligne par chambre (PDF et Excel)
        $this->client->request('GET', "/api/events/$id/pdf/rooming");
        self::assertSame(200, $this->code());
        $this->client->request('GET', "/api/events/$id/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetByName('Rooming')->toArray(null, true, false);
        unlink($file);
        $names = array_values(array_filter(array_column(array_slice($rows, 4), 0), fn ($v) => $v && 'Chambres' !== $v));
        self::assertCount(2, $names);
        self::assertContains('DURAND Anna + MARTIN Luc', $names);
        self::assertSame('Chambre à partager : second occupant à choisir', $rows[array_search('PETIT Zoé', array_column($rows, 0), true)][count($rows[4]) - 1]);

        // Duplication : chambres partagées reprises
        $copy = $this->call('POST', "/api/events/$id/duplicate");
        $byName = array_column(array_map(fn ($x) => ['n' => $x['person']['firstName'], 'mate' => $x['roommateId'], 'id' => $x['id']], $copy['participations']), null, 'n');
        self::assertSame($byName['Luc']['id'], $byName['Anna']['mate']);

        // Retour en single : la chambre n'est plus partagée, des deux côtés ; retrait d'une personne : lien retiré
        $ev = $this->call('PUT', "/api/events/$id/participations", ['participations' => [$row('Anna', 'single')]]);
        $parts = array_column($ev['participations'], null, 'id');
        self::assertSame([null, null], [$parts[$p['Anna']]['roommateId'], $parts[$p['Luc']]['roommateId']]);
        self::assertSame(3 * $nightsPerPerson, $ev['quote']['nightsCount']);
        $this->call('PUT', "/api/events/$id/participations", ['participations' => [$row('Anna', 'twin', 'Luc')]]);
        $ev = $this->call('DELETE', "/api/events/$id/participations/{$p['Anna']}");
        self::assertNull(array_column($ev['participations'], null, 'id')[$p['Luc']]['roommateId']);
    }
}
