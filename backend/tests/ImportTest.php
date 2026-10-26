<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\Person;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Import des classeurs Excel : analyse (contrôles, doublons, copies) puis enregistrement. */
class ImportTest extends ApiTestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmp, 'is_file'));
        parent::tearDown();
    }

    /**
     * Classeur au format des devis de tournée. $roadmapCity / $roadmapDates permettent de simuler
     * une feuille de route restée sur une autre date.
     */
    private function workbook(string $venue = 'Théâtre du Moulin - Lorient', string $dates = '19 au 21 mai 2027', string $roadmapCity = 'Lorient', string $roadmapDay = '2027-05-18'): string
    {
        $wb = new Spreadsheet();
        $s = $wb->getActiveSheet()->setTitle('DEVIS');
        $date = fn (string $ymd) => Date::PHPToExcel(new \DateTimeImmutable($ymd));
        $set = function ($ws, string $cell, $v, bool $isDate = false) use ($date) {
            $ws->setCellValue($cell, $isDate ? $date($v) : $v);
            $isDate && $ws->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        };
        $set($s, 'A2', 'Ville / Théâtre');
        $set($s, 'B2', $venue);
        $set($s, 'A3', 'Dates');
        $set($s, 'B3', $dates);
        $set($s, 'A4', 'Spectacle : ');
        $set($s, 'B4', 'LES OISEAUX DE PASSAGE');
        $set($s, 'A8', 'Arrivée équipe technique le:');
        $set($s, 'B8', '2027-05-18', true);
        $set($s, 'A9', 'Arrivée équipe artistique le:');
        $set($s, 'B9', '2027-05-18', true);
        $set($s, 'A10', 'Départ équipe le : ');
        $set($s, 'B10', '2027-05-22', true);
        foreach (['A' => 'Nom Prénom', 'B' => 'Fonction', 'O' => 'Nom Prénom', 'P' => 'Ville de départ', 'Q' => 'Aller > Ville', 'R' => 'Ville > Home'] as $c => $v) {
            $set($s, "{$c}15", $v);
        }
        $team = [['DURAND Anna', 'Danseuse', 'Paris', 45.5, 30], ['LE BRAS Yann Erwan', 'Régisseur lumière', 'Rennes', 22, 22]];
        foreach ($team as $i => [$name, $role, $city, $out, $ret]) {
            $r = 16 + $i;
            $set($s, "A$r", $name);
            $set($s, "B$r", $role);
            $set($s, "O$r", $name);
            $set($s, "P$r", $city);
            $set($s, "Q$r", $out);
            $set($s, "R$r", $ret);
        }
        $set($s, 'O30', 'DÉFRAIEMENTS');
        $set($s, 'W30', 'BASE : ');
        $set($s, 'X30', 19.5);
        $set($s, 'A31', 'HÉBERGEMENTS');
        $set($s, 'O31', 'REPAS (midi et soir uniquement) ');
        foreach (['A' => 'Nom Prénom', 'B' => 'Chambre', 'O' => 'Nom Prénom'] as $c => $v) {
            $set($s, "{$c}32", $v);
        }
        $days = ['2027-05-18', '2027-05-19', '2027-05-20', '2027-05-21'];
        foreach ($days as $i => $d) {
            $set($s, chr(ord('C') + $i).'32', $d, true);
            $set($s, chr(ord('P') + $i).'32', $d, true);
        }
        foreach ($team as $i => [$name]) {
            $r = 33 + $i;
            $set($s, "A$r", $name);
            $set($s, "B$r", 0 === $i ? 'double' : 'single');
            $set($s, "O$r", $name);
            foreach ($days as $j => $d) {
                $set($s, chr(ord('C') + $j).$r, $j < 3 ? 1 : 0);
                $set($s, chr(ord('P') + $j).$r, 2);
            }
        }
        $set($s, 'A50', 'Cachet 3 représentations');
        $set($s, 'D50', 4200);
        $set($s, 'A52', 'Tva 5,5%');
        $set($s, 'A55', 'Camion ');
        $set($s, 'A56', 'Lyon > Lorient');
        $set($s, 'B56', 820);
        $set($s, 'E56', 1.3);
        $set($s, 'A58', 'Total');

        $f = $wb->createSheet()->setTitle('Feuille de Route');
        $set($f, 'B1', 'LES OISEAUX DE PASSAGE');
        $set($f, 'B2', "$roadmapCity du 19 au 21 mai");
        $set($f, 'B4', "Théâtre du Moulin  |  4 rue du Port, 56100 $roadmapCity  |  02 97 00 00 00");
        $set($f, 'B5', 'Accueil Cie : Marie Test - marie@moulin.test - 06 00 00 00 00');
        $set($f, 'B6', $roadmapDay, true);
        $set($f, 'C6', 'VOYAGE ALLER');
        $rows = [['Anna', 'Paris > Rennes', '9h10 - 10h40', 'ABC123', 'DURAND', 'E-billet TGV'], ['', 'Rennes > Lorient', '11h02 - 12h15', 'cf billet en pj', '', 'TER Direct'],
            ['Yann', 'Rennes > Lorient', 'Autonome', '', 'LE BRAS', 'Véhicule perso']];
        foreach ($rows as $i => $row) {
            foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $j => $c) {
                '' !== $row[$j] && $set($f, $c.(8 + $i), $row[$j]);
            }
        }
        $set($f, 'B12', 'HÔTEL');
        $set($f, 'B13', 'HÔTEL DE LA GARE ** - Anna, Yann');
        $set($f, 'B14', "Adresse : 1 place de la Gare, 56100 $roadmapCity\nTéléphone : 02 97 11 11 11");
        $set($f, 'B15', 'Petit-déjeuner : 7h - 10h');
        $set($f, 'B17', 'WIFI');
        $set($f, 'B18', 'Réseau MOULIN - mot de passe : oiseaux');
        $set($f, 'B20', 'PLANNING');
        $set($f, 'B21', '2027-05-19', true);
        $set($f, 'B22', '9h - 13h');
        $set($f, 'C22', 'Montage');
        $set($f, 'B23', '20h');
        $set($f, 'C23', 'Représentation #1');
        $set($f, 'B24', '2027-05-20', true);
        $set($f, 'B25', '20h');
        $set($f, 'C25', 'Représentation #2');
        $set($f, 'B27', '2027-05-22', true);
        $set($f, 'C27', 'VOYAGE RETOUR');
        $set($f, 'B28', 'Anna');
        $set($f, 'C28', 'Lorient > Paris');
        $set($f, 'D28', '10h05 - 13h20');
        $set($f, 'F28', 'DURAND');
        $set($f, 'G28', 'E-billet TGV');

        $c = $wb->createSheet()->setTitle('Coordonnées');
        foreach (['B' => 'NOM Prénom ', 'C' => 'FONCTION', 'D' => 'TELEPHONE', 'E' => 'MAIL', 'F' => 'REGIME ALIMENTAIRE'] as $col => $v) {
            $set($c, "{$col}8", $v);
        }
        foreach ([['DURAND Anna', 'Danseuse', '06 11 11 11 11', 'anna@test.io', 'Végétarienne'], ['LE BRAS Yann Erwan', 'Régisseur', '06 22 22 22 22', 'yann@test.io', 'RAS']] as $i => $row) {
            foreach (['B', 'C', 'D', 'E', 'F'] as $j => $col) {
                $set($c, $col.(9 + $i), $row[$j]);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($wb))->save($path);
        $this->tmp[] = $path;

        return $path;
    }

    private function upload(int $cid, array $files): ?array
    {
        $this->client->request('POST', "/api/companies/$cid/import", files: ['files' => array_map(
            fn ($name, $path) => new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            array_keys($files), $files)], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testAnalyzeThenImport(): void
    {
        $this->login();
        [$cid] = $this->buildCompany('Cie Import');

        $res = $this->upload($cid, ['Devis_Oiseaux_Lorient.xlsx' => $this->workbook(), 'notes.txt' => __FILE__]);
        self::assertSame(200, $this->code(), json_encode($res));
        [$file, $bad] = $res['files'];
        self::assertStringContainsString('.xlsx', $bad['error']);
        self::assertSame([], $file['warnings']);
        self::assertSame(['include' => true, 'includeRoadmap' => true], $file['defaults']);
        self::assertSame('Les oiseaux de passage', $file['show']['title']);
        self::assertNull($file['show']['id']);
        $e = $file['event'];
        self::assertSame(['Théâtre du Moulin', 'Lorient', '4 rue du Port, 56100 Lorient', '2027-05-19', '2027-05-21', 2], [$e['venue'], $e['city'], $e['address'], $e['firstDate'], $e['lastDate'], $e['performances']]);
        self::assertSame([true, false], array_column($file['team'], 'known'));
        self::assertSame(['team' => 2, 'newPeople' => 1, 'nights' => 6, 'meals' => 16, 'legs' => 4, 'tickets' => 119.5, 'lodgings' => 1, 'sections' => 1, 'schedule' => 3], $file['counts']);

        // Les brouillons sont liés à l'utilisateur : un autre ne peut pas les enregistrer.
        $this->call('POST', '/api/logout');
        $this->createUser('other@test.io', 'other-password-123');
        $this->grant(['other@test.io'], ['company.view', 'events.edit', 'people.edit'], [$cid]);
        $this->login('other@test.io', 'other-password-123');
        $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [0 => ['include' => true]]]);
        self::assertSame(404, $this->code());
        $this->call('POST', '/api/logout');
        $this->login();

        $done = $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [0 => ['include' => true, 'includeRoadmap' => true, 'status' => 'option', 'venue' => 'Théâtre du Moulin (Lorient)']]]);
        self::assertSame(200, $this->code(), json_encode($done));
        self::assertCount(1, $done['created']);
        $ev = $this->call('GET', "/api/events/{$done['created'][0]['id']}");
        self::assertSame(['Théâtre du Moulin (Lorient)', 'option', 4200, 19.5, 5.5, 1.3], [$ev['venue'], $ev['status'], $ev['rates']['fee'], $ev['rates']['mealRate'], $ev['rates']['vatRate'], $ev['rates']['truckRate']]);
        self::assertSame('Les oiseaux de passage', $ev['showTitle']);
        [$anna, $yann] = $ev['participations'];
        self::assertSame(['DURAND', 'Anna', '06 11 11 11 11', 'anna@test.io', 'Végétarienne'], [$anna['person']['lastName'], $anna['person']['firstName'], $anna['person']['phone'], $anna['person']['email'], $anna['person']['diet']]);
        self::assertSame(['LE BRAS', 'Yann Erwan', '', 'Rennes'], [$yann['person']['lastName'], $yann['person']['firstName'], $yann['person']['diet'], $yann['departureCity']]);
        self::assertSame('double', $anna['roomType']);
        $legs = array_map(fn ($l) => [$l['direction'], $l['origin'], $l['destination'], $l['depTime'], $l['mode'], $l['reference'], (float) $l['price']], $anna['legs']);
        self::assertSame([['outbound', 'Paris', 'Rennes', '9h10', 'TGV INOUI', 'ABC123', 45.5], ['outbound', 'Rennes', 'Lorient', '11h02', 'TER', '', 0.0], ['return', 'Lorient', 'Paris', '10h05', 'TGV INOUI', '', 30.0]], $legs);
        // Pas de trajet retour dans la feuille de route : le prix du devis crée un trajet à compléter.
        self::assertSame([['outbound', 'Véhicule perso', 22.0, 'Rennes'], ['return', 'Train', 22.0, 'Lorient']], array_map(fn ($l) => [$l['direction'], $l['mode'], (float) $l['price'], $l['origin']], $yann['legs']));
        self::assertSame(119.5, (float) $ev['quote']['travelTickets']);
        self::assertSame(['Hôtel de la Gare'], array_column($ev['lodgings'], 'name'));
        self::assertSame('02 97 11 11 11', $ev['lodgings'][0]['phone']);
        self::assertSame($ev['lodgings'][0]['id'], $anna['lodgingId']);
        self::assertSame(['Wifi'], array_column($ev['sections'], 'title'));
        self::assertCount(3, $ev['schedule']);
        self::assertSame(3, array_sum(array_column($anna['days'], 'night')));
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'import.event']));
        self::assertCount(3, $this->em()->getRepository(Person::class)->findAll()); // 2 de buildCompany + Yann

        // Le brouillon est consommé ; un second import du même fichier signale la date existante.
        $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [0 => ['include' => true]]]);
        self::assertSame(404, $this->code());
        $again = $this->upload($cid, ['Devis_Oiseaux_Lorient.xlsx' => $this->workbook()]);
        self::assertSame(['exists'], array_column($again['files'][0]['warnings'], 'code'));
        self::assertFalse($again['files'][0]['defaults']['include']);
        self::assertSame($ev['showId'], $again['files'][0]['show']['id']);
    }

    public function testDetectsCopiesAndStaleRoadmap(): void
    {
        $this->login();
        [$cid] = $this->buildCompany('Cie Copies');
        $res = $this->upload($cid, [
            'Devis_Oiseaux_Lorient.xlsx' => $this->workbook(),
            'Devis_Oiseaux_Saint_Malo.xlsx' => $this->workbook(),                                      // copie pas encore remplie
            'Devis_Oiseaux_Brest.xlsx' => $this->workbook('Le Quartz - Brest', '3 et 4 décembre 2027', 'Lorient', '2027-05-18'), // feuille de route restée sur Lorient
        ]);
        [$lorient, $copy, $brest] = $res['files'];
        self::assertTrue($lorient['defaults']['include']);
        self::assertSame(['filename', 'duplicate'], array_column($copy['warnings'], 'code'));
        self::assertFalse($copy['defaults']['include']);
        self::assertSame(['Le Quartz', 'Brest', '2027-12-03', '2027-12-04', ''], [$brest['event']['venue'], $brest['event']['city'], $brest['event']['firstDate'], $brest['event']['lastDate'], $brest['event']['address']]);
        self::assertTrue($brest['roadmap']['mismatch']);
        self::assertSame(['roadmap', 'arrivals'], array_column($brest['warnings'], 'code'));
        self::assertSame(['include' => true, 'includeRoadmap' => false], $brest['defaults']);

        // Rooming recalé : il couvre encore mai, les dates d'arrivée restent celles du rooming.
        $done = $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [
            0 => ['include' => true, 'includeRoadmap' => true],
            2 => ['include' => true, 'includeRoadmap' => false],
        ]]);
        self::assertSame(['Lorient', 'Brest'], array_column($done['created'], 'city'));
        $ev = $this->call('GET', "/api/events/{$done['created'][1]['id']}");
        self::assertSame([], $ev['lodgings']);
        self::assertSame([], $ev['schedule']);
        // Sans feuille de route, les prix du devis donnent un trajet aller et un retour à compléter par personne.
        self::assertSame(119.5, (float) $ev['quote']['travelTickets']);
        self::assertCount(2, $ev['participations'][0]['legs']);
        self::assertSame(1, (int) $this->em()->getRepository(Event::class)->count(['city' => 'Brest']));
    }

    public function testRequiresRights(): void
    {
        $this->login();
        [$cid] = $this->buildCompany('Cie Droits');
        $this->call('POST', '/api/logout');
        $this->createUser('ro@test.io', 'ro-password-1234');
        $this->grant(['ro@test.io'], ['company.view', 'events.edit'], [$cid]);
        $this->login('ro@test.io', 'ro-password-1234');
        $res = $this->upload($cid, ['Devis_Oiseaux_Lorient.xlsx' => $this->workbook()]);
        self::assertSame(403, $this->code());
        self::assertStringContainsString('Droit nécessaire', $res['error']);

        // Sans droit sur les voyages ni sur les finances : la date est créée sans trajets ni tarifs.
        $this->call('POST', '/api/logout');
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->grant(['ed@test.io'], ['company.view', 'events.edit', 'people.edit', 'shows.edit'], [$cid]);
        $this->login('ed@test.io', 'ed-password-12345');
        $res = $this->upload($cid, ['Devis_Oiseaux_Lorient.xlsx' => $this->workbook()]);
        $done = $this->call('POST', "/api/companies/$cid/import/{$res['id']}", ['files' => [0 => ['include' => true, 'includeRoadmap' => true]]]);
        self::assertTrue($done['skippedTravel']);
        $ev = $this->em()->find(Event::class, $done['created'][0]['id']);
        self::assertSame('0.00', $ev->fee);
        self::assertCount(0, $ev->participations->first()->legs);
    }
}
