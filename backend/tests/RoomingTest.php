<?php

namespace App\Tests;

/** Chambres partagées : double / twin à deux, appartement à plusieurs ; rooming-list par chambre, nuitées du devis par chambre. */
class RoomingTest extends ApiTestCase
{
    public function testSharedRooms(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        foreach (['Zoé', 'Paul'] as $n) {
            $this->call('POST', "/api/companies/$cid/people", ['lastName' => 'PETIT', 'firstName' => $n]);
        }
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $id = $ev['id'];
        $this->call('PUT', "/api/events/$id/rates", ['nightRate' => '50', 'fee' => '1000']);
        $p = array_column(array_map(fn ($x) => ['n' => $x['person']['firstName'], 'id' => $x['id']], $ev['participations']), 'id', 'n');
        $nights = count(array_filter($ev['participations'][0]['days'], fn ($d) => $d['night']));
        // Toutes les lignes, comme l'onglet Équipe : [prénom => [type, colocataires]]
        $save = fn (array $rooms) => $this->call('PUT', "/api/events/$id/participations", ['participations' => array_map(
            fn ($n) => ['id' => $p[$n], 'role' => '', 'roomType' => $rooms[$n][0] ?? 'single', 'roomWith' => array_map(fn ($m) => $p[$m], $rooms[$n][1] ?? [])],
            array_keys($p))]);
        $with = fn ($ev, $n) => array_column($ev['participations'], null, 'id')[$p[$n]]['roomWith'];
        $type = fn ($ev, $n) => array_column($ev['participations'], null, 'id')[$p[$n]]['roomType'];

        // Double Anna + Luc ; appartement Zoé + Paul + … : la double est limitée à deux
        $ev = $save(['Anna' => ['double', ['Luc']], 'Luc' => ['double', ['Anna', 'Zoé']], 'Zoé' => ['double', ['Luc']]]);
        self::assertSame([[$p['Luc']], [$p['Anna']], 'double', []], [$with($ev, 'Anna'), $with($ev, 'Luc'), $type($ev, 'Luc'), $with($ev, 'Zoé')]);
        self::assertSame(3 * $nights, $ev['quote']['nightsCount'], '4 personnes, 3 chambres');

        // Appartement à trois : Zoé, Paul et Luc (Luc quitte la double d'Anna)
        $ev = $save(['Anna' => ['double'], 'Zoé' => ['appartement', ['Paul', 'Luc']], 'Paul' => ['appartement', ['Zoé']], 'Luc' => ['appartement', ['Zoé']]]);
        self::assertEqualsCanonicalizing([$p['Paul'], $p['Luc']], $with($ev, 'Zoé'));
        self::assertEqualsCanonicalizing([$p['Zoé'], $p['Luc']], $with($ev, 'Paul'));
        self::assertSame([[], 'appartement'], [$with($ev, 'Anna'), $type($ev, 'Luc')]);
        self::assertSame(2 * $nights, $ev['quote']['nightsCount'], 'Anna seule + un appartement');
        self::assertStringContainsString('par chambre', array_values(array_filter($ev['quote']['lines'], fn ($l) => str_starts_with($l['label'], 'Nuitées')))[0]['label']);

        // Rooming-list : une ligne par chambre (PDF et Excel), double sans second occupant signalée
        $this->client->request('GET', "/api/events/$id/pdf/rooming");
        self::assertSame(200, $this->code());
        $this->client->request('GET', "/api/events/$id/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetByName('Rooming')->toArray(null, true, false);
        unlink($file);
        $names = array_values(array_filter(array_column(array_slice($rows, 4), 0), fn ($v) => $v && 'Chambres' !== $v));
        self::assertCount(2, $names);
        self::assertCount(1, array_filter($names, fn ($n) => 3 === count(explode(' + ', $n))), 'appartement à trois sur une ligne');
        self::assertSame('Chambre à partager : second occupant à choisir', $rows[array_search('DURAND Anna', array_column($rows, 0), true)][count($rows[4]) - 1]);

        // Duplication : chambres partagées reprises
        $copy = $this->call('POST', "/api/events/$id/duplicate");
        $flat = array_values(array_filter($copy['participations'], fn ($x) => 'Zoé' === $x['person']['firstName']))[0];
        self::assertCount(2, $flat['roomWith']);

        // Retrait d'une personne : l'appartement reste partagé à deux ; retour en single : partage retiré
        $ev = $this->call('DELETE', "/api/events/$id/participations/{$p['Paul']}");
        unset($p['Paul']);
        self::assertSame([$p['Luc']], $with($ev, 'Zoé'));
        $ev = $save(['Zoé' => ['single'], 'Luc' => ['appartement', ['Zoé']]]);
        self::assertSame([[], []], [$with($ev, 'Zoé'), $with($ev, 'Luc')]);
    }
}
