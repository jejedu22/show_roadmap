<?php

namespace App\Tests;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Exports Excel : totaux et montants en formules, reliés entre onglets, égaux aux montants de l'application et recalculés si l'on modifie le fichier. */
class XlsxFormulasTest extends ApiTestCase
{
    private function book(string $url): Spreadsheet
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->code());
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $book = IOFactory::load($file);
        unlink($file);

        return $book;
    }

    /** Cellule de la colonne $col sur la ligne dont la colonne A vaut $label. */
    private static function at(Spreadsheet $book, string $sheet, string $label, string $col = 'B'): \PhpOffice\PhpSpreadsheet\Cell\Cell
    {
        $ws = $book->getSheetByName($sheet);
        foreach ($ws->getRowIterator() as $row) {
            if ($label === $ws->getCell('A'.$row->getRowIndex())->getValue()) {
                return $ws->getCell($col.$row->getRowIndex());
            }
        }
        self::fail("« $label » absent de $sheet");
    }

    public function testTotalsAreFormulas(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $id = $ev['id'];
        $this->call('PUT', "/api/events/$id/rates", ['mealRate' => '20', 'breakfastRate' => '8', 'nightRate' => '90', 'truckRate' => '1,2', 'vatRate' => '5,5', 'fee' => '3000', 'transportBudget' => '100']);
        $ev = $this->call('POST', "/api/events/$id/participations", ['personIds' => array_column($ev['availablePeople'], 'id')]);
        $pids = array_column($ev['participations'], 'id');
        self::assertCount(2, $pids);
        $items = "/api/events/$id/items";
        $this->call('POST', "$items/leg", ['participationId' => $pids[0], 'direction' => 'outbound', 'day' => '2027-01-18', 'origin' => 'Paris', 'destination' => 'Lyon', 'price' => '60']);
        $this->call('POST', "$items/leg", ['participationId' => $pids[1], 'direction' => 'return', 'day' => '2027-01-21', 'origin' => 'Lyon', 'destination' => 'Lille', 'price' => '75,50']);
        $this->call('POST', "$items/expense", ['participationId' => $pids[0], 'direction' => 'outbound', 'day' => '2027-01-18', 'kind' => 'Taxi', 'amount' => '12']);
        $this->call('POST', "$items/expense", ['participationId' => $pids[1], 'direction' => 'return', 'day' => '2027-01-21', 'kind' => 'Taxi', 'amount' => '-5']);
        $this->call('POST', "$items/extra", ['label' => 'Navette', 'amount' => '40', 'kind' => 'team_transport']);
        $this->call('POST', "$items/extra", ['label' => 'Location', 'amount' => '15']);
        $this->call('POST', "$items/truck", ['label' => 'Aller', 'km' => '100']);
        $q = $this->call('GET', "/api/events/$id")['quote'];
        $costs = $this->call('GET', "/api/events/$id")['personCosts'];

        foreach (["/api/events/$id/export.xlsx", "/api/events/$id/costs.xlsx"] as $url) {
            $book = $this->book($url);
            $total = self::at($book, 'Devis', 'Total TTC');
            self::assertTrue($total->isFormula(), $url);
            foreach (['Total frais annexes' => 'annexTotal', 'Total HT' => 'totalHt', 'TVA 5,5 %' => 'vat', 'Total TTC' => 'totalTtc'] as $label => $key) {
                self::assertTrue(self::at($book, 'Devis', $label)->isFormula(), "$url $label");
                self::assertEqualsWithDelta($q[$key], self::at($book, 'Devis', $label)->getCalculatedValue(), 0.001, "$url $label");
            }
            foreach ($q['lines'] as $l) {
                self::assertEqualsWithDelta($l['amount'], self::at($book, 'Devis', $l['label'])->getCalculatedValue(), 0.001, "$url {$l['label']}");
            }
            self::assertTrue(self::at($book, 'Devis', $q['lines'][1]['label'])->isFormula(), 'transport équipe relié à l\'onglet Voyages');

            // Défraiements : nombre et montant par personne en formules
            $def = $book->getSheetByName('Défraiements');
            $head = $def->rangeToArray('A5:'.$def->getHighestColumn().'5', null, false, false, true)[5];
            [$n, $amount] = [array_search('Total', $head, true), array_search('Montant', $head, true)];
            self::assertNotFalse($n);
            $checked = 0;
            foreach ($def->getRowIterator() as $row) {
                $r = $row->getRowIndex();
                if (in_array($def->getCell("A$r")->getValue(), ['DURAND Anna', 'MARTIN Luc'], true)) {
                    self::assertTrue($def->getCell("$n$r")->isFormula() && $def->getCell("$amount$r")->isFormula(), "$url Défraiements $r");
                    ++$checked;
                }
            }
            self::assertSame(6, $checked, '2 personnes × 3 grilles');

            // Modification dans le fichier : le prix d'un billet change, le devis suit
            $ws = $book->getSheetByName('Voyages');
            $ws->getCell('K6')->setValue(160);
            Calculation::getInstance($book)->clearCalculationCache();
            self::assertEqualsWithDelta($q['totalHt'] + 100, self::at($book, 'Devis', 'Total HT')->getCalculatedValue(), 0.001, "$url recalcul");
        }

        // Par personne (export des coûts) : égal aux coûts de l'application, et suit l'onglet Voyages
        $book = $this->book("/api/events/$id/costs.xlsx");
        self::assertSame(2, $book->getIndex($book->getSheetByName('Par personne')), 'après Devis et Tarifs');
        foreach ($costs as $c) {
            $total = self::at($book, 'Par personne', $c['name'], 'L');
            self::assertTrue($total->isFormula());
            self::assertEqualsWithDelta($c['outbound'] + $c['return'] + $c['perDiem'], $total->getCalculatedValue(), 0.001, $c['name']);
            self::assertEqualsWithDelta($c['perDiem'], self::at($book, 'Par personne', $c['name'], 'G')->getCalculatedValue(), 0.001);
        }
        self::assertEqualsWithDelta($q['travelTickets'], self::at($book, 'Devis', 'dont billets (train, avion…)')->getCalculatedValue(), 0.001);
        $book->getSheetByName('Voyages')->getCell('K6')->setValue(160);
        Calculation::getInstance($book)->clearCalculationCache();
        self::assertEqualsWithDelta(160, self::at($book, 'Par personne', 'DURAND Anna', 'H')->getCalculatedValue(), 0.001);

        // Rooming : nuits par chambre en formule
        $book = $this->book("/api/events/$id/export.xlsx");
        $ws = $book->getSheetByName('Rooming');
        $col = array_search('Nuits', $ws->rangeToArray('A4:'.$ws->getHighestColumn().'4', null, false, false, true)[4], true);
        self::assertTrue(self::at($book, 'Rooming', 'DURAND Anna', $col)->isFormula());
    }
}
