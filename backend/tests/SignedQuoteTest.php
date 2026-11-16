<?php

namespace App\Tests;

use App\Service\Import\SignedQuoteParser;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Devis signé : lecture des devis PDF de la compagnie (texte de « pdftotext -layout », exemples anonymisés), enregistrement
 * et comparaison avec le réalisé (déplacements de l'équipe, repas, nuitées).
 */
class SignedQuoteTest extends ApiTestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__."/fixtures/devis/$name.txt");
    }

    public function testParseQuotes(): void
    {
        $p = new SignedQuoteParser();
        $a = $p->parse(self::fixture('devis-a'));
        self::assertSame(['26-01A', '2026-02-20', 1, 23, 24, 47, 28, 1100.0, 0.0], [$a['number'], $a['date'], $a['performances'], $a['lunches'], $a['dinners'], $a['meals'], $a['nights'], $a['teamTravel'], $a['decor']]);
        self::assertSame("Ville d'Exemple / service culture", $a['organizer']);
        self::assertSame('Jeudi 3 décembre 26', $a['period']);
        self::assertSame(7072.9, $a['totalHt'], 'TOTAL devis, pas TOTAL avenant');
        self::assertSame([5000.0, 972.9], [$a['cession'], $a['allowances']], 'cession et total des défraiements, pour information');
        self::assertCount(2, $a['checks']);
        self::assertSame([], $a['warnings']);

        // Hôtel noté « - » le jour du départ, décor à part, période sur la ligne suivant « PERIODE »
        $b = $p->parse(self::fixture('devis-b'));
        self::assertSame(['26-02B', '2025-09-23', 2, 17, 17, 34, 17, 1800.0, 1500.0, 3300.0], [$b['number'], $b['date'], $b['performances'], $b['lunches'], $b['dinners'], $b['meals'], $b['nights'], $b['teamTravel'], $b['decor'], $b['travelTotal']]);
        self::assertSame('décembre 2026 - à déterminer', $b['period']);
        self::assertSame('SNCF', $b['teamLines'][0]['detail']);
        self::assertSame([6000.0, 703.8], [$b['cession'], $b['allowances']]);
        self::assertSame([], $b['warnings']);

        // Total des repas incohérent avec les colonnes : signalé
        $c = $p->parse(str_replace('Total défraiements repas                                                                                                                            34', 'Total défraiements repas                                                                                                                            36', self::fixture('devis-b')));
        self::assertNotEmpty($c['warnings']);

        $this->expectException(\InvalidArgumentException::class);
        $p->parse("Facture n° 12\nMontant 100,00 €");
    }

    public function testSaveCompareAndImport(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        $ev = $this->call('POST', "/api/events/$id/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        self::assertNull($ev['signedQuote']);
        $pid = $ev['participations'][0]['id'];
        $this->call('POST', "/api/events/$id/items/leg", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'price' => '60']);
        $this->call('POST', "/api/events/$id/items/expense", ['participationId' => $pid, 'direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '12']);
        $ev = $this->call('POST', "/api/events/$id/items/extra", ['label' => 'Navette', 'amount' => '8', 'kind' => 'team_transport']);
        self::assertEquals(80, $ev['quote']['teamTransport'], 'billets + dépenses + transport équipe');
        $nights = 0;
        foreach ($ev['participations'] as $p) {
            foreach ((array) $p['days'] as $d) {
                $nights += $d['night'];
            }
        }
        self::assertSame($nights, $ev['quote']['personNightsCount'], 'nuitées par personne');

        $ev = $this->call('PUT', "/api/events/$id/signed-quote", ['number' => '26-01A', 'date' => '2026-02-20', 'signedOn' => '2026-03-04', 'teamTravel' => '1 100,00', 'meals' => '47', 'nights' => '28']);
        self::assertEquals(['number' => '26-01A', 'date' => '2026-02-20', 'signedOn' => '2026-03-04', 'teamTravel' => 1100, 'meals' => 47, 'nights' => 28],
            array_intersect_key($ev['signedQuote'], array_flip(['number', 'date', 'signedOn', 'teamTravel', 'meals', 'nights'])));
        self::assertSame([], $ev['signedQuote']['lines']);
        $ev = $this->call('PUT', "/api/events/$id/signed-quote", ['number' => '26-01A', 'teamTravel' => '', 'meals' => '47', 'nights' => '']);
        self::assertNull($ev['signedQuote']['teamTravel'], 'champ vidé : pas de comparaison');

        // Sans droit finances : ni lecture ni modification
        $this->createUser('lea@test.io', 'lea-password-123');
        $this->grant(['lea@test.io'], ['company.view'], [$ev['companyId']]);
        $this->login('lea@test.io', 'lea-password-123');
        self::assertNull($this->call('GET', "/api/events/$id")['signedQuote']);
        $this->call('PUT', "/api/events/$id/signed-quote", ['meals' => '1']);
        self::assertSame(403, $this->code());
        $this->login();

        // Import : un fichier qui n'est pas un PDF est refusé
        $txt = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($txt, 'pas un pdf');
        $this->client->request('POST', "/api/events/$id/signed-quote/pdf", [], ['file' => new UploadedFile($txt, 'devis.pdf', 'application/pdf', null, true)], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(400, $this->code());

        // Import d'un vrai PDF (texte du devis en police à chasse fixe) : lu, rien n'est enregistré avant validation
        if (null === shell_exec('command -v pdftotext')) {
            self::markTestIncomplete('pdftotext absent : import PDF non testé');
        }
        $dompdf = new Dompdf((new Options())->setDefaultFont('DejaVu Sans Mono'));
        $dompdf->loadHtml('<pre style="font-family:\'DejaVu Sans Mono\';font-size:4.6pt">'.htmlspecialchars(self::fixture('devis-a')).'</pre>', 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $pdf = tempnam(sys_get_temp_dir(), 'q');
        file_put_contents($pdf, $dompdf->output());
        $this->client->request('POST', "/api/events/$id/signed-quote/pdf", [], ['file' => new UploadedFile($pdf, 'Devis.pdf', 'application/pdf', null, true)], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $read = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['26-01A', 47, 28, 1100], [$read['number'], $read['meals'], $read['nights'], (int) $read['teamTravel']]);
        self::assertSame('Devis.pdf', $read['file']);
        self::assertNull($this->call('GET', "/api/events/$id")['signedQuote']['nights'], 'pas encore enregistré');

        $ev = $this->call('DELETE', "/api/events/$id/signed-quote");
        self::assertNull($ev['signedQuote']);
    }

    /** Devis initial complet : détail conservé et modifiable, totaux déduits des lignes et des repas midi / soir. */
    public function testDetailIsKeptAndEditable(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $id = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20'])['id'];
        $quote = ['number' => '26-02B', 'date' => '2025-09-23', 'show' => 'Spectacle B', 'organizer' => 'Théâtre', 'period' => 'décembre 2026',
            'performances' => '2', 'cession' => '6 000,00', 'lunches' => '17', 'dinners' => '17', 'meals' => '99', 'nights' => '17', 'allowances' => '703,80',
            'lines' => [['label' => '13 personnes', 'detail' => 'SNCF', 'amount' => '1 500,00'], ['label' => 'Location', 'detail' => 'minibus', 'amount' => '300'], ['label' => '', 'detail' => '', 'amount' => '']],
            'teamTravel' => '1', 'decor' => '1500', 'totalHt' => '10003,80', 'totalTtc' => '10003.8'];
        $sq = $this->call('PUT', "/api/events/$id/signed-quote", $quote)['signedQuote'];
        self::assertEquals(1800, $sq['teamTravel'], 'somme des lignes, pas le montant saisi');
        self::assertSame(34, $sq['meals'], 'midis + soirs');
        self::assertCount(2, $sq['lines'], 'ligne vide ignorée');
        self::assertEquals(['Spectacle B', 'Théâtre', 'décembre 2026', 2, 6000, 703.8, 1500, 10003.8, 10003.8],
            [$sq['show'], $sq['organizer'], $sq['period'], $sq['performances'], $sq['cession'], $sq['allowances'], $sq['decor'], $sq['totalHt'], $sq['totalTtc']]);
        $steps = array_column($this->call('GET', "/api/events/$id")['readiness']['steps'], null, 'key');
        self::assertSame(['Devis initial', true, 'N° 26-02B'], [$steps['signed']['label'], $steps['signed']['done'], $steps['signed']['detail']]);

        // Modification : une ligne retirée, repas détaillés vidés → le total des repas saisi est repris
        $quote['lines'] = [$quote['lines'][0]];
        [$quote['lunches'], $quote['dinners'], $quote['meals']] = ['', '', '30'];
        $sq = $this->call('PUT', "/api/events/$id/signed-quote", $quote)['signedQuote'];
        self::assertEquals([1500, 30, null, null], [$sq['teamTravel'], $sq['meals'], $sq['lunches'], $sq['dinners']]);

        // Sans lignes : montant global des déplacements
        $quote['lines'] = [];
        $quote['teamTravel'] = '1 234,50';
        self::assertEquals(1234.5, $this->call('PUT', "/api/events/$id/signed-quote", $quote)['signedQuote']['teamTravel']);

        // Excel : onglet « Devis initial » après Devis, comparaison au réalisé en formules
        foreach (["/api/events/$id/export.xlsx", "/api/events/$id/costs.xlsx"] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(200, $this->code());
            $file = tempnam(sys_get_temp_dir(), 'x');
            file_put_contents($file, $this->client->getInternalResponse()->getContent());
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            unlink($file);
            self::assertSame(1, $book->getIndex($book->getSheetByName('Devis initial')), $url);
            $ws = $book->getSheetByName('Devis initial');
            $found = [];
            foreach ($ws->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                $found[(string) $ws->getCell("A$r")->getValue()] = $r;
            }
            self::assertSame('Théâtre', $ws->getCell('B'.$found['Organisateur'])->getValue());
            $travel = $found['Déplacements de l\'équipe (€ HT)'];
            self::assertStringStartsWith('=', (string) $ws->getCell("B$travel")->getValue());
            self::assertEqualsWithDelta(1234.5, $ws->getCell("B$travel")->getCalculatedValue(), 0.001);
            self::assertEqualsWithDelta(-1234.5, $ws->getCell("D$travel")->getCalculatedValue(), 0.001, 'aucun voyage saisi');
            self::assertEquals(30, $ws->getCell('B'.$found['Repas (midi + soir)'])->getCalculatedValue());
            self::assertEquals(17, $ws->getCell('B'.$found['Nuitées (hôtel + petit-déjeuner, par personne)'])->getCalculatedValue());
        }
    }

    /** Droit « Saisir le devis initial » : distinct de « Modifier le devis », et lecture réservée à « Voir les montants ». */
    public function testSignedEditPermission(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        foreach ([['tom@test.io', ['finance.edit']], ['zoe@test.io', ['finance.view', 'signed.edit']], ['max@test.io', ['signed.edit']]] as [$email, $perms]) {
            $this->createUser($email, 'password-123456');
            $this->grant([$email], ['company.view', ...$perms], [$ev['companyId']]);
        }

        $this->login('tom@test.io', 'password-123456');
        $this->call('PUT', "/api/events/$id/signed-quote", ['meals' => '1']);
        self::assertSame(403, $this->code(), 'modifier le devis ne suffit pas');
        self::assertContains('signed', array_column($this->call('GET', "/api/events/$id")['readiness']['steps'], 'key'), 'onglet visible en lecture');

        $this->login('max@test.io', 'password-123456');
        $this->call('PUT', "/api/events/$id/signed-quote", ['meals' => '1']);
        self::assertSame(403, $this->code(), 'sans « Voir les montants »');
        self::assertNotContains('signed', array_column($this->call('GET', "/api/events/$id")['readiness']['steps'], 'key'));

        $this->login('zoe@test.io', 'password-123456');
        self::assertSame(12, $this->call('PUT', "/api/events/$id/signed-quote", ['meals' => '12'])['signedQuote']['meals']);
        $this->call('PUT', "/api/events/$id/rates", ['fee' => '100']);
        self::assertSame(403, $this->code(), 'le devis lui-même reste en lecture');
    }
}
