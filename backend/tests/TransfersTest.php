<?php

namespace App\Tests;

use PhpOffice\PhpSpreadsheet\IOFactory;

/** Arrivées et départs (transferts) : arrivée du dernier trajet aller, départ du premier trajet retour, en Excel et en PDF. */
class TransfersTest extends ApiTestCase
{
    public function testArrivalsAndDepartures(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        $ev = $this->call('POST', "/api/events/$id/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $pid = array_column(array_map(fn ($p) => ['n' => $p['person']['firstName'], 'id' => $p['id']], $ev['participations']), 'id', 'n');
        $leg = fn ($p, $dir, $day, $o, $d, $dep, $arr, $mode = 'Train', $ref = '') => $this->call('POST', "/api/events/$id/items/leg",
            ['participationId' => $p, 'direction' => $dir, 'day' => $day, 'origin' => $o, 'destination' => $d, 'depTime' => $dep, 'arrTime' => $arr, 'mode' => $mode, 'reference' => $ref]);
        // Luc : correspondance à l'aller (arrivée = 2e trajet), deux trajets au retour (départ = 1er)
        $leg($pid['Luc'], 'outbound', '2027-01-18', 'Lille', 'Paris', '7h00', '8h05');
        $leg($pid['Luc'], 'outbound', '2027-01-18', 'Paris', 'Beauvais', '9h10', '10h25', 'Train', 'ABC123');
        $leg($pid['Luc'], 'return', '2027-01-21', 'Paris', 'Lille', '20h46', '21h48');
        $leg($pid['Luc'], 'return', '2027-01-21', 'Beauvais', 'Paris', '18h35', '19h50');
        // Anna : arrive la veille, pas de retour
        $leg($pid['Anna'], 'outbound', '2027-01-17', 'Lyon', 'Beauvais', '13h00', '17h30');

        $this->client->request('GET', "/api/events/$id/transfers.xlsx");
        self::assertSame(200, $this->code());
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = IOFactory::load($file)->getSheetByName('Arrivées et départs')->toArray(null, true, false);
        unlink($file);
        $data = array_values(array_filter($rows, fn ($r) => in_array($r[0], ['DURAND Anna', 'MARTIN Luc'], true)));
        self::assertSame(['DURAND Anna', 'MARTIN Luc'], array_column($data, 0), 'dans l\'ordre des arrivées');
        // Nom, Fonction, Téléphone, Arrivée le, Heure, Lieu, Transport, Départ le, Heure, Lieu, Transport
        self::assertSame(['17h30', 'Beauvais', 'Train'], [$data[0][4], $data[0][5], $data[0][6]]);
        self::assertSame('Retour à réserver', $data[0][9]);
        self::assertSame(['10h25', 'Beauvais', 'Train · ABC123', '18h35', 'Beauvais'], [$data[1][4], $data[1][5], $data[1][6], $data[1][8], $data[1][9]]);
        self::assertSame('Téléphone', array_values(array_filter($rows, fn ($r) => 'Nom' === $r[0]))[0][2]);

        // Aussi dans l'export complet ; PDF disponible avec le simple droit de consultation, sans les téléphones
        $this->client->request('GET', "/api/events/$id/export.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        self::assertNotNull(IOFactory::load($file)->getSheetByName('Arrivées et départs'));
        unlink($file);
        $this->createUser('lea@test.io', 'lea-password-123');
        $this->grant(['lea@test.io'], ['company.view'], [$cid]);
        $this->login('lea@test.io', 'lea-password-123');
        $this->client->request('GET', "/api/events/$id/pdf/transfers");
        self::assertSame(200, $this->code());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('content-type'));
        $this->client->request('GET', "/api/events/$id/transfers.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $rows = IOFactory::load($file)->getSheetByName('Arrivées et départs')->toArray(null, true, false);
        unlink($file);
        self::assertSame('Arrivée le', array_values(array_filter($rows, fn ($r) => 'Nom' === $r[0]))[0][2], 'pas de colonne Téléphone sans le droit');
    }
}
