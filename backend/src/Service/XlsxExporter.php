<?php

namespace App\Service;

use App\Entity\Event;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Exports Excel d'une date : complet (devis, rooming, défraiements, voyages, coordonnées) ou coûts seuls (lien de partage). */
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

        $this->travelSheets($book, $ev);

        $withContacts && $this->sheet($book, 'Coordonnées', ['Nom', 'Fonction', 'Téléphone', 'E-mail', 'Régime'],
            $ev->participations->map(fn ($p) => [$p->person->fullName(), $p->role, $p->person->phone, $p->person->email, $p->person->diet])->getValues());

        return $this->write($book);
    }

    /**
     * Tout ce qui touche aux coûts d'une date, sans coordonnées ni rooming : le fichier envoyé par le lien de partage des coûts.
     * Devis, tarifs, coûts par personne, défraiements jour par jour, billets, dépenses et remboursements, camion, frais supplémentaires.
     */
    public function exportCosts(Event $ev): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $q = $this->quotes->compute($ev);
        $period = $ev->firstDate->format('d/m/Y').($ev->lastDate != $ev->firstDate ? ' au '.$ev->lastDate->format('d/m/Y') : '');
        $rows = [['Spectacle', $ev->show->title], ['Lieu', trim($ev->venue.($ev->city ? ' — '.$ev->city : ''))], ['Dates', $period], ['', ''],
            ['Cachet', $q['fee']]];
        foreach ($q['lines'] as $l) {
            $rows[] = [$l['label'], $l['amount']];
        }
        array_push($rows, ['Total frais annexes', $q['annexTotal']], ['Total HT', $q['totalHt']],
            ['TVA '.(float) $ev->vatRate.' %', $q['vat']], ['Total TTC', $q['totalTtc']], ['', ''],
            ['Dont billets de train / avion…', $q['travelTickets']], ['Dont dépenses et remboursements de trajet', $q['travelExpenses']]);
        $this->sheet($book, 'Devis', ['Poste', 'Montant HT (€)'], $rows);

        $this->sheet($book, 'Tarifs', ['Tarif', 'Valeur'], [['Cachet HT (€)', (float) $ev->fee], ['Repas / personne / jour (€)', (float) $ev->mealRate],
            ['Petit-déjeuner (€)', (float) $ev->breakfastRate], ['Nuitée (€)', (float) $ev->nightRate], ['Camion (€ HT / km)', (float) $ev->truckRate],
            ['TVA (%)', (float) $ev->vatRate]]);

        $rows = [];
        $costs = array_column($this->quotes->personCosts($ev), null, 'participationId');
        foreach ($ev->participations as $p) {
            $c = $costs[$p->id];
            $tickets = ['outbound' => 0.0, 'return' => 0.0];
            foreach ($p->legs as $l) {
                $tickets['return' === $l->direction ? 'return' : 'outbound'] += (float) $l->price;
            }
            $travel = $c['outbound'] + $c['return'];
            $rows[] = [$p->person->fullName(), $p->role, $p->departureCity, $c['meals'], $c['breakfasts'], $c['nights'], $c['perDiem'],
                round($tickets['outbound'], 2), round($tickets['return'], 2), $c['expenses'], round($travel, 2), round($travel + $c['perDiem'], 2)];
        }
        $this->sheet($book, 'Par personne', ['Nom', 'Fonction', 'Départ de', 'Repas', 'Petits-déj.', 'Nuitées', 'Défraiements (€)', 'Billets aller (€)',
            'Billets retour (€)', 'Dépenses / remb. (€)', 'Total voyages (€)', 'Total (€)'], $rows);

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->days as $d) {
                if ($d->meals || $d->breakfast || $d->night) {
                    $rows[] = [$p->person->fullName(), $d->day->format('d/m/Y'), $d->meals, $d->breakfast, $d->night,
                        round($d->meals * (float) $ev->mealRate + $d->breakfast * (float) $ev->breakfastRate, 2)];
                }
            }
        }
        $this->sheet($book, 'Défraiements', ['Nom', 'Date', 'Repas', 'Petits-déj.', 'Nuitée', 'Défraiement (€)'], $rows);

        $this->travelSheets($book, $ev, true);

        $this->sheet($book, 'Transport décor', ['Trajet', 'Km', 'Tarif (€ HT / km)', 'Montant HT (€)'],
            $ev->trucks->map(fn ($t) => [$t->label, (float) $t->km, (float) $ev->truckRate, round((float) $t->km * (float) $ev->truckRate, 2)])->getValues());
        $this->sheet($book, 'Frais supplémentaires', ['Libellé', 'Poste', 'Montant HT (€)'],
            $ev->extras->map(fn ($x) => [$x->label, 'team_transport' === $x->kind ? 'Transport équipe' : 'Autres frais', (float) $x->amount])->getValues());

        return $this->write($book);
    }

    public static function costsFilename(Event $ev): string
    {
        return str_replace([' ', '/', '\''], ['_', '-', ''], 'Couts_'.$ev->show->title.'_'.$ev->venue).'.xlsx';
    }

    /** Onglets Voyages (billets) et Dépenses trajets ; $always : onglet des dépenses même vide. */
    private function travelSheets(Spreadsheet $book, Event $ev, bool $always = false): void
    {
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

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->expenses as $x) {
                $rows[] = [$p->person->fullName(), 'return' === $x->direction ? 'Retour' : 'Aller', $x->day->format('d/m/Y'), $x->kind, (float) $x->amount, $x->comment];
            }
        }
        ($rows || $always) && $this->sheet($book, 'Dépenses trajets', ['Nom', 'Sens', 'Date', 'Type', 'Montant (€)', 'Commentaire'], $rows);
    }

    private function write(Spreadsheet $book): string
    {
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
