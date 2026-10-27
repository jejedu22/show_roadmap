<?php

namespace App\Tests;

use App\Entity\AuditLog;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Import de l'onglet « Horaires TGV » vers les voyages d'une date. */
class TrainSheetTest extends ApiTestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tmp, 'is_file'));
        parent::tearDown();
    }

    /**
     * Onglet fictif reprenant les variantes des classeurs réels : prénom sur la ligne des gares ou des horaires,
     * date propre à un trajet, nouvelle date en cours d'onglet, « Autonome », trajet en voiture sur une ligne,
     * échange, annulation totale, montant isolé, totaux en bas, trajet isolé sous un bloc, heure au format Excel.
     */
    private function workbook(): string
    {
        $wb = new Spreadsheet();
        $wb->getActiveSheet()->setTitle('DEVIS');
        $s = $wb->createSheet()->setTitle('Horaires TGV');
        $date = function (string $cell, string $ymd) use ($s) {
            $s->setCellValue($cell, Date::PHPToExcel(new \DateTimeImmutable($ymd)));
            $s->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        };
        $row = function (int $r, array $cells) use ($s) {
            foreach ($cells as $col => $v) {
                $s->setCellValue($col.$r, $v);
            }
        };
        $date('B2', '2027-05-17');
        $date('J2', '2027-05-21');
        // Anna : deux trajets, le second un autre jour ; retour avec un échange
        $row(4, ['C' => 'Lyon', 'D' => 'Paris', 'K' => 'Lorient', 'L' => 'Paris']);
        $row(5, ['B' => 'Anna', 'C' => '7h04', 'D' => '9h02', 'E' => 'Direct', 'F' => 'ABC123', 'G' => 60, 'J' => 'Anna', 'K' => '10h05', 'L' => '13h20', 'M' => 'Direct', 'N' => '** QWE987 **', 'O' => 70]);
        $row(6, ['C' => 'Paris', 'D' => 'Lorient', 'K' => 'Paris', 'L' => 'Lyon', 'N' => 'Echange', 'O' => -15]);
        $date('E6', '2027-05-18');
        $row(7, ['C' => '15h01', 'D' => '18h40', 'E' => 'OUIGO', 'F' => 'Cf billet en pj', 'G' => 25, 'K' => '14h04', 'L' => '16h00', 'M' => 'TER', 'N' => 'RTY456', 'O' => 30]);
        // Totaux d'un bloc à ignorer, séparés par une ligne vide
        $row(9, ['G' => 85, 'O' => 85]);
        // Nouvelle date pour les blocs suivants
        $date('B11', '2027-05-18');
        // Jean-Christophe (« JC ») : prénom sur la ligne des gares, voiture sur une ligne, billet annulé et remboursé
        $row(13, ['C' => 'Marseille', 'D' => 'Aix TGV', 'E' => 'Véhicule perso', 'G' => 12.5]);
        $row(14, ['B' => 'JC', 'C' => 'Aix TGV', 'D' => 'Paris', 'J' => 'JC', 'K' => 'Autonome - Avec Anna']);
        $row(15, ['C' => '8h41', 'D' => '11h50', 'E' => 'Direct', 'F' => 'ZZZ111', 'G' => 48]);
        $row(16, ['F' => 'Annulation', 'G' => -48]);
        $row(17, ['C' => 'Paris', 'D' => 'Lorient']);
        $s->setCellValue('C18', 13 / 24); // 13h00 en heure Excel
        $s->getStyle('C18')->getNumberFormat()->setFormatCode('hh:mm');
        $row(18, ['D' => '16h33', 'E' => 'Direct', 'F' => 'XYZ222', 'G' => 89]);
        // Trajet isolé sous le bloc, sans prénom : rattaché à JC
        $row(21, ['K' => 'Paris', 'L' => 'Marseille', 'M' => 'Véhicule perso à rembourser', 'O' => 9]);
        // Personne inconnue de la date
        $row(24, ['B' => 'Zoé', 'C' => 'Autonome']);
        $row(27, ['G' => 500, 'O' => 500]);

        $path = tempnam(sys_get_temp_dir(), 'tgv').'.xlsx';
        (new Xlsx($wb))->save($path);
        $this->tmp[] = $path;

        return $path;
    }

    private function preview(int $ev, string $path): ?array
    {
        $this->client->request('POST', "/api/events/$ev/legs/excel", files: ['file' => new UploadedFile($path, 'Devis_Oiseaux_Lorient.xlsx', null, null, true)],
            server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Date à Lorient avec Anna DURAND et Jean-Christophe AUBERT ; renvoie [eventId, participations par prénom]. */
    private function fixture(): array
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        $this->call('POST', "/api/companies/$cid/people", ['lastName' => 'AUBERT', 'firstName' => 'Jean-Christophe', 'function' => 'Régisseur vidéo']);
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Le Moulin', 'firstDate' => '2027-05-19', 'lastDate' => '2027-05-20']);
        $people = array_filter($ev['availablePeople'], fn ($p) => in_array($p['lastName'], ['DURAND', 'AUBERT'], true));
        $ev = $this->call('POST', "/api/events/{$ev['id']}/participations", ['personIds' => array_column($people, 'id')]);
        $parts = [];
        foreach ($ev['participations'] as $p) {
            $parts[$p['person']['firstName']] = $p['id'];
        }

        return [$ev['id'], $parts];
    }

    public function testPreviewReadsTheSheetVariants(): void
    {
        [$ev, $parts] = $this->fixture();
        $res = $this->preview($ev, $this->workbook());
        self::assertSame(200, $this->code(), json_encode($res));
        $byName = array_column($res['persons'], null, 'name');
        self::assertSame(['Anna', 'JC', 'Zoé'], array_keys($byName));
        self::assertSame([$parts['Anna'], $parts['Jean-Christophe'], null], array_column($res['persons'], 'participationId'), 'JC = initiales de Jean-Christophe');

        $brief = fn (array $legs) => array_map(fn ($l) => [$l['direction'][0], $l['day'], $l['origin'], $l['destination'], $l['depTime'], $l['arrTime'], $l['mode'], $l['reference'], (float) $l['price']], $legs);
        self::assertSame([
            ['o', '2027-05-17', 'Lyon', 'Paris', '7h04', '9h02', 'TGV INOUI', 'ABC123', 60.0],
            ['o', '2027-05-18', 'Paris', 'Lorient', '15h01', '18h40', 'OUIGO', '', 25.0],
            ['r', '2027-05-21', 'Lorient', 'Paris', '10h05', '13h20', 'TGV INOUI', 'QWE987', 55.0],
            ['r', '2027-05-21', 'Paris', 'Lyon', '14h04', '16h00', 'TER', 'RTY456', 30.0],
        ], $brief($byName['Anna']['legs']));
        self::assertStringContainsString('Cf billet en pj', $byName['Anna']['legs'][1]['note']);
        self::assertStringContainsString('Echange − 15,00 €', $byName['Anna']['legs'][2]['note']);

        $jc = $byName['JC']['legs'];
        self::assertSame([
            ['o', '2027-05-18', 'Marseille', 'Aix TGV', '', '', 'Véhicule perso', '', 12.5],
            ['o', '2027-05-18', 'Aix TGV', 'Paris', '8h41', '11h50', 'TGV INOUI', 'ZZZ111', 0.0],
            ['o', '2027-05-18', 'Paris', 'Lorient', '13h00', '16h33', 'TGV INOUI', 'XYZ222', 89.0],
            ['r', '2027-05-21', '', '', '', '', 'Véhicule perso', '', 0.0],
            ['r', '2027-05-21', 'Paris', 'Marseille', '', '', 'Véhicule perso', '', 9.0],
        ], $brief($jc));
        self::assertTrue($jc[1]['cancelled'], 'billet entièrement remboursé : trajet abandonné');
        self::assertStringContainsString('Avec Anna', $jc[3]['note']);
        self::assertContains('Retour : trajet sans prénom (Paris → Marseille) rattaché à JC, à vérifier.', $res['warnings']);
        self::assertEqualsCanonicalizing(['Anna DURAND', 'Jean-Christophe AUBERT'], array_column($res['participants'], 'name'));
    }

    public function testImportAddsOrReplacesLegs(): void
    {
        [$ev, $parts] = $this->fixture();
        $this->call('POST', "/api/events/$ev/items/leg", ['participationId' => $parts['Anna'], 'direction' => 'outbound', 'day' => '2027-05-17',
            'origin' => 'Lyon', 'destination' => 'Paris', 'depTime' => '7h04', 'price' => '60']);
        $res = $this->preview($ev, $this->workbook());
        $anna = array_column($res['persons'], null, 'name')['Anna'];
        self::assertSame([true, false, false, false], array_column($anna['legs'], 'exists'), 'trajet déjà saisi repéré');

        // Ajout des trajets non encore saisis
        $new = array_values(array_filter($anna['legs'], fn ($l) => !$l['exists']));
        $out = $this->call('POST', "/api/events/$ev/legs/import", ['legs' => array_map(fn ($l) => $l + ['participationId' => $parts['Anna']], $new)]);
        self::assertSame(200, $this->code(), json_encode($out));
        $legs = array_column($out['participations'], null, 'id')[$parts['Anna']]['legs'];
        self::assertCount(4, $legs);
        self::assertSame(['QWE987', 55.0], [$legs[2]['reference'], (float) $legs[2]['price']]);
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'event.legs_import']));

        // Remplacement : les trajets existants de la personne sont d'abord effacés
        $out = $this->call('POST', "/api/events/$ev/legs/import", ['replace' => true, 'legs' => [$anna['legs'][0] + ['participationId' => $parts['Anna']]]]);
        self::assertCount(1, array_column($out['participations'], null, 'id')[$parts['Anna']]['legs']);

        // Participant d'une autre date, fichier sans l'onglet, droits
        $this->call('POST', "/api/events/$ev/legs/import", ['legs' => [$anna['legs'][0] + ['participationId' => 999999]]]);
        self::assertSame(404, $this->code());
        $wb = new Spreadsheet();
        $path = tempnam(sys_get_temp_dir(), 'tgv').'.xlsx';
        (new Xlsx($wb))->save($path);
        $this->tmp[] = $path;
        $res = $this->preview($ev, $path);
        self::assertSame(400, $this->code());
        self::assertStringContainsString('Horaires TGV', $res['error']);
    }
}
