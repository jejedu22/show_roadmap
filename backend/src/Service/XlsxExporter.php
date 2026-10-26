<?php

namespace App\Service;

use App\Entity\Event;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Export Excel d'une date : devis, rooming, défraiements, voyages, coordonnées. */
class XlsxExporter
{
    public function __construct(private QuoteCalculator $quotes)
    {
    }

    /** $withContacts : inclut l'onglet Coordonnées (droit « Voir les coordonnées »). */
    public function export(Event $ev, bool $withContacts = true): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $q = $this->quotes->compute($ev);
        $rows = [['Cachet', $q['fee']]];
        foreach ($q['lines'] as $l) {
            $rows[] = [$l['label'], $l['amount']];
        }
        array_push($rows, ['Total frais annexes', $q['annexTotal']], ['Total HT', $q['totalHt']],
            ['TVA '.(float) $ev->vatRate.' %', $q['vat']], ['Total TTC', $q['totalTtc']]);
        $this->sheet($book, 'Devis', ['Poste', 'Montant HT (€)'], $rows);

        $dates = $ev->spanDates();
        $rows = [];
        foreach ($ev->participations as $p) {
            $nights = array_map(fn ($d) => $p->day($d)?->night ?? 0, $dates);
            $rows[] = [$p->person->fullName(), $p->role, $p->roomType, $p->lodging?->name ?? '', ...$nights, array_sum($nights)];
        }
        $this->sheet($book, 'Rooming', ['Nom', 'Fonction', 'Chambre', 'Hébergement', ...array_map(fn ($d) => $d->format('d/m'), $dates), 'Total'], $rows);

        $this->sheet($book, 'Défraiements', ['Nom', 'Repas', 'Petits-déj.', 'Total (€)'],
            array_map(fn ($c) => [$c['name'], $c['meals'], $c['breakfasts'], $c['perDiem']], $this->quotes->personCosts($ev)));

        $rows = [];
        foreach ($ev->participations as $p) {
            // Allers puis retours, chacun dans l'ordre chronologique
            $legs = $p->legs->toArray();
            usort($legs, fn ($a, $b) => [$a->direction !== 'outbound', $a->day, self::minutes($a->depTime)]
                <=> [$b->direction !== 'outbound', $b->day, self::minutes($b->depTime)]);
            foreach ($legs as $l) {
                $rows[] = [$p->person->fullName(), 'return' === $l->direction ? 'Retour' : 'Aller', $l->day->format('d/m/Y'),
                    $l->origin, $l->destination, $l->depTime, $l->arrTime, $l->mode, $l->reference, (float) $l->price, $l->bookingUrl];
            }
        }
        $this->sheet($book, 'Voyages', ['Nom', 'Sens', 'Date', 'De', 'À', 'Départ', 'Arrivée', 'Mode', 'Référence', 'Prix (€)', 'Lien billet'], $rows);

        $withContacts && $this->sheet($book, 'Coordonnées', ['Nom', 'Fonction', 'Téléphone', 'E-mail', 'Régime'],
            $ev->participations->map(fn ($p) => [$p->person->fullName(), $p->role, $p->person->phone, $p->person->email, $p->person->diet])->getValues());

        $book->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    private function sheet(Spreadsheet $book, string $title, array $header, array $rows): void
    {
        $ws = $book->createSheet();
        $ws->setTitle($title);
        // Écriture explicite : une saisie commençant par « = » ne doit jamais devenir une formule.
        foreach ([$header, ...$rows] as $r => $row) {
            foreach (array_values($row) as $c => $v) {
                $cell = [$c + 1, $r + 1];
                is_int($v) || is_float($v)
                    ? $ws->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC)
                    : $ws->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
            }
        }
        $ws->getStyle('1:1')->getFont()->setBold(true);
        foreach ($ws->getColumnIterator() as $col) {
            $ws->getColumnDimension($col->getColumnIndex())->setAutoSize(true);
        }
    }

    /** « 9h05 », « 09:05 » → minutes ; heure absente en dernier. */
    private static function minutes(string $t): int
    {
        return preg_match('/^(\d{1,2})\s*[h:]\s*(\d{0,2})/i', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : 1440;
    }
}
