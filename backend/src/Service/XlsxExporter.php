<?php

namespace App\Service;

use App\Entity\Event;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exports Excel d'une date : complet (devis, rooming, défraiements, voyages, coordonnées) ou coûts seuls (lien de partage).
 *
 * Chaque onglet est un tableau mis en forme aux couleurs du thème de la compagnie : bandeau de titre (spectacle, lieu, dates),
 * en-têtes colorés, montants en euros, vraies dates Excel, ligne de total, volets figés, filtres et mise en page d'impression.
 */
class XlsxExporter
{
    private const MONEY = '#,##0.00\ "€";[Red]\-#,##0.00\ "€"';
    /** Première ligne sous le bandeau */
    private const HEADER_ROW = 4;

    /** Types de colonne : largeur par défaut, format, alignement ; « sum » : additionnée dans la ligne de total. */
    private const TYPES = [
        'text' => ['width' => 14, 'format' => null, 'align' => Alignment::HORIZONTAL_LEFT, 'sum' => false],
        'money' => ['width' => 13, 'format' => self::MONEY, 'align' => Alignment::HORIZONTAL_RIGHT, 'sum' => true],
        'rate' => ['width' => 13, 'format' => self::MONEY, 'align' => Alignment::HORIZONTAL_RIGHT, 'sum' => false],
        'int' => ['width' => 9, 'format' => '0', 'align' => Alignment::HORIZONTAL_CENTER, 'sum' => true],
        'km' => ['width' => 10, 'format' => '#,##0.0', 'align' => Alignment::HORIZONTAL_RIGHT, 'sum' => true],
        'percent' => ['width' => 10, 'format' => '0.0#\ "%"', 'align' => Alignment::HORIZONTAL_RIGHT, 'sum' => false],
        'date' => ['width' => 18, 'format' => '[$-40C]ddd dd/mm/yyyy', 'align' => Alignment::HORIZONTAL_LEFT, 'sum' => false],
        'url' => ['width' => 12, 'format' => null, 'align' => Alignment::HORIZONTAL_CENTER, 'sum' => false],
    ];

    /** Couleurs du thème actif (RRGGBB), lues à chaque export */
    private array $c = [];

    public function __construct(private QuoteCalculator $quotes, private Themes $themes)
    {
    }

    /** Couleur du thème actif au format Excel (RRGGBB). */
    private function rgb(string $role): string
    {
        return strtoupper(ltrim($this->c[$role], '#'));
    }

    /** $withContacts : inclut l'onglet Coordonnées (droit « Voir les coordonnées »). */
    public function export(Event $ev, bool $withContacts = true): string
    {
        $book = $this->book($ev, 'Export');
        $this->quoteSheet($book, $ev, false);

        $dates = $ev->spanDates();
        $rows = [];
        foreach ($ev->participations as $p) {
            $nights = array_map(fn ($d) => $p->day($d)?->night ?? 0, $dates);
            $rows[] = [$p->person->fullName(), $p->role, ucfirst($p->roomType), $p->lodging?->name ?? '', ...$nights, array_sum($nights)];
        }
        $this->table($book, $ev, 'Rooming', [['Nom', 'text', 22], ['Fonction', 'text', 20], ['Chambre', 'text', 12], ['Hébergement', 'text', 24],
            ...array_map(fn ($d) => [ExcelDate::PHPToExcel($d), 'int', 11, '[$-40C]ddd dd/mm'], $dates), ['Nuitées', 'int']], $rows,
            ['total' => 'Total', 'tab' => $this->rgb('mint')]);

        $this->perDiemSheet($book, $ev);
        $this->travelSheet($book, $ev);

        $this->invitationSheet($book, $ev);

        $withContacts && $this->table($book, $ev, 'Coordonnées', [['Nom', 'text', 22], ['Fonction', 'text', 20], ['Téléphone', 'text', 16],
            ['E-mail', 'text', 30], ['Régime', 'text', 22]],
            $ev->participations->map(fn ($p) => [$p->person->fullName(), $p->role, $p->person->phone, $p->person->email, $p->person->diet])->getValues(),
            ['tab' => $this->rgb('ink')]);

        return $this->write($book);
    }

    /**
     * Tout ce qui touche aux coûts d'une date, sans coordonnées ni rooming : le fichier envoyé par le lien de partage des coûts.
     * Devis, tarifs, coûts par personne, défraiements (grilles par jour), voyages (billets, dépenses et remboursements).
     */
    public function exportCosts(Event $ev): string
    {
        $book = $this->book($ev, 'Coûts');
        $this->quoteSheet($book, $ev, true);

        $this->table($book, $ev, 'Tarifs', [['Tarif', 'text', 30], ['Valeur', 'rate', 14]], [
            ['Cachet HT', (float) $ev->fee], ['Repas / personne / jour', (float) $ev->mealRate], ['Petit-déjeuner', (float) $ev->breakfastRate],
            ['Nuitée', (float) $ev->nightRate], ['Camion (HT / km)', (float) $ev->truckRate], ['TVA', [(float) $ev->vatRate, 'percent']],
        ], ['tab' => $this->rgb('violet'), 'filter' => false]);

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
        $this->table($book, $ev, 'Par personne', [['Nom', 'text', 22], ['Fonction', 'text', 18], ['Départ de', 'text', 14], ['Repas', 'int'],
            ['Petits-déj.', 'int', 11], ['Nuitées', 'int'], ['Défraiements', 'money', 14], ['Billets aller', 'money', 14], ['Billets retour', 'money', 14],
            ['Dépenses / remb.', 'money', 16], ['Total voyages', 'money', 14], ['Total', 'money', 14]], $rows,
            ['total' => 'Total', 'tab' => $this->rgb('sun'), 'emphasis' => 11]);

        $this->perDiemSheet($book, $ev);
        $this->travelSheet($book, $ev);

        return $this->write($book);
    }

    public static function costsFilename(Event $ev): string
    {
        return str_replace([' ', '/', '\''], ['_', '-', ''], 'Couts_'.$ev->show->title.'_'.$ev->venue).'.xlsx';
    }

    /** Devis : postes, sous-totaux, TVA, total TTC mis en avant ; $detail : part des billets et des dépenses de trajet. */
    private function quoteSheet(Spreadsheet $book, Event $ev, bool $detail): void
    {
        $q = $this->quotes->compute($ev);
        $rows = [['Cachet', $q['fee']]];
        foreach ($q['lines'] as $l) {
            $rows[] = [$l['label'], $l['amount']];
        }
        $styles = [];
        $styles[count($rows)] = 'subtotal';
        $rows[] = ['Total frais annexes', $q['annexTotal']];
        $styles[count($rows)] = 'subtotal';
        $rows[] = ['Total HT', $q['totalHt']];
        $rows[] = ['TVA '.self::number((float) $ev->vatRate).' %', $q['vat']];
        $styles[count($rows)] = 'grand';
        $rows[] = ['Total TTC', $q['totalTtc']];
        if ($detail) {
            $styles[count($rows)] = 'note';
            $rows[] = ['dont billets (train, avion…)', $q['travelTickets']];
            $styles[count($rows)] = 'note';
            $rows[] = ['dont dépenses et remboursements de trajet', $q['travelExpenses']];
        }
        $this->table($book, $ev, 'Devis', [['Poste', 'text', 52], ['Montant', 'rate', 16]], $rows,
            ['styles' => $styles, 'tab' => $this->rgb('violet'), 'filter' => false, 'stripes' => false]);
    }

    /**
     * Onglet Voyages : billets, puis dépenses et remboursements, alignés sur les mêmes colonnes (montants dans la colonne Prix),
     * et total général des voyages.
     */
    private function travelSheet(Spreadsheet $book, Event $ev): void
    {
        $ws = $this->newSheet($book, $ev, 'Voyages', $this->rgb('coral'));
        $rows = [];
        foreach ($ev->participations as $p) {
            // Allers puis retours, chacun dans l'ordre chronologique
            $legs = $p->legs->toArray();
            usort($legs, fn ($a, $b) => [$a->direction !== 'outbound', $a->day, self::minutes($a->depTime)]
                <=> [$b->direction !== 'outbound', $b->day, self::minutes($b->depTime)]);
            foreach ($legs as $l) {
                $rows[] = [$p->person->fullName(), 'return' === $l->direction ? 'Retour' : 'Aller', $l->day,
                    $l->origin, $l->destination, $l->depTime, $l->arrTime, $l->mode, $l->reference, (float) $l->price, $l->bookingUrl];
            }
        }
        $tickets = $this->block($ws, self::HEADER_ROW, [['Nom', 'text', 22], ['Sens', 'text', 8], ['Date', 'date'], ['De', 'text', 18], ['À', 'text', 18],
            ['Départ', 'text', 8], ['Arrivée', 'text', 8], ['Mode', 'text', 12], ['Référence', 'text', 12], ['Prix', 'money', 12], ['Billet', 'url']],
            $rows, ['heading' => 'Billets', 'total' => 'Total billets', 'direction' => 1]);

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->expenses as $x) {
                $rows[] = [$p->person->fullName(), 'return' === $x->direction ? 'Retour' : 'Aller', $x->day, $x->kind, $x->comment, (float) $x->amount, ''];
            }
        }
        // Type sous « De », commentaire sur « À » → « Référence », montant sous « Prix »
        $expenses = $this->block($ws, $tickets['next'], [['Nom', 'text'], ['Sens', 'text'], ['Date', 'date'], ['Type', 'text'],
            ['Commentaire', 'text', null, null, 5], ['Montant', 'money'], ['', 'text']], $rows,
            ['heading' => 'Dépenses et remboursements (montant négatif = remboursement)', 'total' => 'Total dépenses et remboursements',
                'direction' => 1, 'widths' => false]);

        $price = Coordinate::stringFromColumnIndex(10);
        $sum = array_filter([$tickets['total'] ? $price.$tickets['total'] : null, $expenses['total'] ? $price.$expenses['total'] : null]);
        $this->grandTotal($ws, $expenses['next'], 11, 'Total voyages', [10 => '='.($sum ? implode('+', $sum) : '0')]);
        $this->finish($ws, $ev, 11);
    }

    /**
     * Onglet Défraiements, comme le tableau de l'onglet Séjour : une grille personnes × jours pour les repas, les petits-déjeuners
     * et les nuitées, avec total, tarif et montant par personne, puis le total général.
     */
    private function perDiemSheet(Spreadsheet $book, Event $ev): void
    {
        $ws = $this->newSheet($book, $ev, 'Défraiements', $this->rgb('sun'));
        $dates = $ev->spanDates();
        $r = self::HEADER_ROW;
        $amounts = [];
        $width = 0;
        foreach ([['meals', 'Repas', (float) $ev->mealRate], ['breakfast', 'Petits-déjeuners', (float) $ev->breakfastRate],
            ['night', 'Nuitées', (float) $ev->nightRate]] as $i => [$field, $label, $rate]) {
            $rows = [];
            foreach ($ev->participations as $p) {
                $counts = array_map(fn ($d) => $p->day($d)?->$field ?? 0, $dates);
                $n = array_sum($counts);
                $rows[] = [$p->person->fullName(), ...$counts, $n, $rate, round($n * $rate, 2)];
            }
            $b = $this->block($ws, $r, [['Nom', 'text', 22], ...array_map(fn ($d) => [ExcelDate::PHPToExcel($d), 'int', 10, '[$-40C]ddd dd/mm'], $dates),
                ['Total', 'int'], ['Tarif', 'rate', 12], ['Montant', 'money', 14]], $rows,
                ['heading' => $label, 'total' => 'Total '.mb_strtolower($label), 'emphasis' => count($dates) + 1, 'widths' => 0 === $i]);
            $width = $b['width'];
            $b['total'] && $amounts[] = Coordinate::stringFromColumnIndex($width).$b['total'];
            $r = $b['next'];
        }
        $this->grandTotal($ws, $r, $width, 'Total défraiements (repas, petits-déjeuners, nuitées)', [$width => '='.($amounts ? implode('+', $amounts) : '0')]);
        $this->finish($ws, $ev, $width);
    }

    /** Onglet Invitations (export complet seulement) : invités par représentation, places mises à disposition et restantes. */
    private function invitationSheet(Spreadsheet $book, Event $ev): void
    {
        $sum = Invitations::summary($ev);
        $perfs = Invitations::performances($ev);
        $rows = [];
        foreach ([...array_map(fn ($s) => [$s->id, $s], $perfs), ['none', null]] as [$key, $s]) {
            foreach ($ev->invitations as $i) {
                // Représentation retirée du planning (ou renommée) : l'invitation est à répartir, comme dans le bilan
                $of = $i->performance && isset($sum['byPerformance'][$i->performance->id]) ? $i->performance->id : 'none';
                if ($of === $key) {
                    $rows[] = [$s ? $s->day : '', $s ? trim($s->time.($s->detail ? ' · '.$s->detail : '')) : ($perfs ? 'À répartir' : 'Toutes'),
                        \App\Entity\Invitation::KINDS[$i->kind], $i->lastName, $i->firstName, $i->structure, $i->places];
                }
            }
        }
        $ws = $this->newSheet($book, $ev, 'Invitations', $this->rgb('mint'),
            sprintf('Places mises à disposition par le lieu : %d · attribuées : %d (Cie %d, Pro %d) · restantes : %d', $sum['quota'], $sum['used'], $sum['company'], $sum['pro'], $sum['left']));
        $b = $this->block($ws, self::HEADER_ROW, [['Date', 'date'], ['Représentation', 'text', 22], ['Type', 'text', 8], ['Nom', 'text', 20], ['Prénom', 'text', 16],
            ['Structure', 'text', 28], ['Places', 'int']], $rows, ['total' => 'Total']);
        $rows && $ws->setAutoFilter('A'.self::HEADER_ROW.':G'.$b['last']);
        $this->finish($ws, $ev, $b['width'], self::HEADER_ROW);
    }

    private function book(Event $ev, string $kind): Spreadsheet
    {
        $this->c = Themes::forTemplates($this->themes->colorsFor($ev->show->company)); // couleurs de la compagnie
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('Show Roadmap')->setTitle($kind.' — '.$ev->show->title.' — '.$ev->venue)
            ->setSubject($ev->show->title)->setCompany($ev->show->company->name);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10)->getColor()->setRGB($this->rgb('ink'));

        return $book;
    }

    /** Un onglet d'un seul tableau : bandeau, tableau, filtres, volets figés, impression. */
    private function table(Spreadsheet $book, Event $ev, string $title, array $columns, array $rows, array $opts = []): void
    {
        $ws = $this->newSheet($book, $ev, $title, $opts['tab'] ?? $this->rgb('violet'), $opts['note'] ?? null);
        $b = $this->block($ws, self::HEADER_ROW, $columns, $rows, $opts);
        if (($opts['filter'] ?? true) && $rows) {
            $ws->setAutoFilter('A'.self::HEADER_ROW.':'.Coordinate::stringFromColumnIndex($b['width']).($b['last']));
        }
        $this->finish($ws, $ev, $b['width'], self::HEADER_ROW);
    }

    /** Onglet vide avec son bandeau : titre, spectacle, lieu, dates, note éventuelle. */
    private function newSheet(Spreadsheet $book, Event $ev, string $title, string $tab, ?string $note = null): Worksheet
    {
        $ws = $book->createSheet();
        $ws->setTitle($title);
        $ws->getTabColor()->setRGB($tab);
        $ws->setShowGridlines(false);
        $ws->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($this->rgb('violet'));
        $ws->getRowDimension(1)->setRowHeight(24);
        $period = $ev->firstDate->format('d/m/Y').($ev->lastDate != $ev->firstDate ? ' au '.$ev->lastDate->format('d/m/Y') : '');
        $ws->setCellValueExplicit('A2', implode(' · ', array_filter([$ev->show->title, trim($ev->venue.($ev->city ? ', '.$ev->city : '')), $period])), DataType::TYPE_STRING);
        $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB($this->rgb('muted'));
        if (null !== $note) {
            $ws->setCellValueExplicit('A3', $note, DataType::TYPE_STRING);
            $ws->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB($this->rgb('muted'));
        }

        return $ws;
    }

    /**
     * Un tableau à partir de la ligne $h : intitulé de section éventuel, en-têtes, lignes, ligne de total.
     * Une colonne peut couvrir plusieurs colonnes de la feuille (« span », cellules fusionnées), pour aligner deux tableaux d'un même onglet.
     *
     * @param list<array{0: string|float, 1: string, 2?: ?int, 3?: ?string, 4?: int}> $columns [libellé, type, largeur, format de l'en-tête, span]
     * @param list<list<mixed>>                                                       $rows    une valeur peut être [valeur, type] pour changer le type d'une cellule
     * @param array{total?: string, heading?: string, styles?: array<int, string>, stripes?: bool, emphasis?: int, direction?: int, widths?: bool} $opts
     *
     * @return array{header: int, first: int, last: int, total: ?int, next: int, width: int, cols: list<int>} lignes et colonnes de la feuille utilisées
     */
    private function block(Worksheet $ws, int $h, array $columns, array $rows, array $opts = []): array
    {
        // Colonnes de la feuille : début et fin de chaque colonne du tableau
        $cols = $ends = [];
        $sc = 1;
        foreach ($columns as $col) {
            $cols[] = $sc;
            $sc += $col[4] ?? 1;
            $ends[] = $sc - 1;
        }
        $width = $sc - 1;
        $last = Coordinate::stringFromColumnIndex($width);
        $merge = function (int $c, int $r) use ($ws, $cols, $ends) {
            $cols[$c] !== $ends[$c] && $ws->mergeCells([$cols[$c], $r, $ends[$c], $r]);
        };

        if (isset($opts['heading'])) {
            $ws->setCellValueExplicit("A$h", $opts['heading'], DataType::TYPE_STRING);
            $ws->getStyle("A$h")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($this->rgb('ink'));
            $ws->getRowDimension($h)->setRowHeight(20);
            ++$h;
        }

        // En-têtes
        foreach ($columns as $c => $col) {
            $cell = [$cols[$c], $h];
            is_float($col[0]) ? $ws->setCellValueExplicit($cell, $col[0], DataType::TYPE_NUMERIC) : $ws->setCellValueExplicit($cell, $col[0], DataType::TYPE_STRING);
            isset($col[3]) && $ws->getStyle($cell)->getNumberFormat()->setFormatCode($col[3]);
            if (($opts['widths'] ?? true) && 1 === ($col[4] ?? 1)) {
                $ws->getColumnDimensionByColumn($cols[$c])->setWidth($col[2] ?? self::TYPES[$col[1]]['width']);
            }
            $merge($c, $h);
        }
        $head = $ws->getStyle("A$h:$last$h");
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('violet'));
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension($h)->setRowHeight(28);
        foreach ($columns as $c => $col) {
            self::align($ws->getStyle([$cols[$c], $h])->getAlignment(), self::TYPES[$col[1]]['align']);
        }

        // Lignes
        $r = $h;
        foreach ($rows as $i => $row) {
            ++$r;
            foreach (array_values($row) as $c => $v) {
                [$v, $type] = is_array($v) ? $v : [$v, $columns[$c][1]];
                $this->cell($ws, $cols[$c], $r, $v, $type);
                $merge($c, $r);
            }
            $style = $opts['styles'][$i] ?? null;
            $range = $ws->getStyle("A$r:$last$r");
            $range->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($this->rgb('line'));
            $range->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            if ('subtotal' === $style) {
                $range->getFont()->setBold(true);
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('violetSoft'));
            } elseif ('grand' === $style) {
                $range->getFont()->setBold(true)->setSize(12);
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('sun'));
                $ws->getRowDimension($r)->setRowHeight(22);
            } elseif ('note' === $style) {
                $range->getFont()->setItalic(true)->getColor()->setRGB($this->rgb('muted'));
                $range->getAlignment()->setIndent(1);
            } elseif (($opts['stripes'] ?? true) && 1 === $i % 2) {
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('stripe'));
            }
            if (isset($opts['direction'])) {
                $ws->getStyle([$cols[$opts['direction']], $r])->getFont()->setBold(true)
                    ->getColor()->setRGB('Retour' === $row[$opts['direction']] ? $this->rgb('coralDark') : $this->rgb('violet'));
            }
            if (isset($opts['emphasis'])) {
                $ws->getStyle([$cols[$opts['emphasis']], $r])->getFont()->setBold(true);
            }
        }
        if (!$rows) {
            ++$r;
            $ws->setCellValueExplicit("A$r", 'Aucune ligne', DataType::TYPE_STRING);
            $this->format($ws, 1, $r, 'text');
            $ws->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB($this->rgb('muted'));
        }
        $lastRow = $r;

        // Total : formules SOMME, pour rester juste si l'on modifie le fichier
        $totalRow = null;
        if (isset($opts['total']) && $rows) {
            $first = $h + 1;
            $totalRow = ++$r;
            $ws->setCellValueExplicit("A$r", $opts['total'], DataType::TYPE_STRING);
            $this->format($ws, 1, $r, 'text');
            foreach ($columns as $c => $col) {
                $merge($c, $r);
                if (self::TYPES[$col[1]]['sum'] && $c > 0) {
                    $letter = Coordinate::stringFromColumnIndex($cols[$c]);
                    $ws->setCellValueExplicit([$cols[$c], $r], "=SUM($letter$first:$letter$lastRow)", DataType::TYPE_FORMULA);
                    $this->format($ws, $cols[$c], $r, $col[1]);
                }
            }
            $total = $ws->getStyle("A$r:$last$r");
            $total->getFont()->setBold(true);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('sun'));
            $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($this->rgb('ink'));
            $ws->getRowDimension($r)->setRowHeight(20);
        }

        return ['header' => $h, 'first' => $h + 1, 'last' => $lastRow, 'total' => $totalRow, 'next' => $r + 2, 'width' => $width, 'cols' => $cols];
    }

    /** Ligne de total général d'un onglet à plusieurs tableaux : $cells => formule, dans les colonnes de la feuille indiquées. */
    private function grandTotal(Worksheet $ws, int $r, int $width, string $label, array $formulas, string $type = 'money'): void
    {
        $last = Coordinate::stringFromColumnIndex($width);
        $ws->setCellValueExplicit("A$r", $label, DataType::TYPE_STRING);
        $this->format($ws, 1, $r, 'text');
        foreach ($formulas as $col => $formula) {
            $ws->setCellValueExplicit([$col, $r], $formula, DataType::TYPE_FORMULA);
            $this->format($ws, $col, $r, $type);
        }
        $style = $ws->getStyle("A$r:$last$r");
        $style->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->rgb('ink'));
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension($r)->setRowHeight(24);
    }

    /** Volets figés (et en-tête répété à l'impression si $header), mise en page A4, pied de page. */
    private function finish(Worksheet $ws, Event $ev, int $width, ?int $header = null): void
    {
        $ws->freezePane('B'.($header ? $header + 1 : self::HEADER_ROW));
        $setup = $ws->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($width > 6 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0);
        $header && $setup->setRowsToRepeatAtTopByStartAndEnd($header, $header);
        $ws->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $ws->getHeaderFooter()->setOddFooter('&L&8 '.self::footer($ev->show->title.' — '.$ev->venue).'&R&8 Page &P / &N');
        $ws->setSelectedCell('A'.($header ? $header + 1 : self::HEADER_ROW));
    }

    private function cell(Worksheet $ws, int $col, int $row, mixed $v, string $type): void
    {
        if ($v instanceof \DateTimeInterface) {
            $ws->setCellValueExplicit([$col, $row], ExcelDate::PHPToExcel($v), DataType::TYPE_NUMERIC);
        } elseif ('url' === $type) {
            // Lien cliquable seulement pour une adresse web ; le texte reste court, l'adresse complète en info-bulle
            if (preg_match('~^https?://~i', (string) $v)) {
                $ws->setCellValueExplicit([$col, $row], 'Ouvrir ↗', DataType::TYPE_STRING);
                $ws->getCell([$col, $row])->getHyperlink()->setUrl((string) $v)->setTooltip((string) $v);
                $ws->getStyle([$col, $row])->getFont()->setUnderline(true)->getColor()->setRGB($this->rgb('violet'));
            } elseif ('' !== (string) $v) {
                $ws->setCellValueExplicit([$col, $row], (string) $v, DataType::TYPE_STRING);
            }
        } elseif (is_int($v) || is_float($v)) {
            $ws->setCellValueExplicit([$col, $row], $v, DataType::TYPE_NUMERIC);
        } else {
            // Écriture explicite : une saisie commençant par « = » ne doit jamais devenir une formule.
            $ws->setCellValueExplicit([$col, $row], (string) $v, DataType::TYPE_STRING);
        }
        $this->format($ws, $col, $row, $type);
    }

    private function format(Worksheet $ws, int $col, int $row, string $type): void
    {
        $style = $ws->getStyle([$col, $row]);
        null !== self::TYPES[$type]['format'] && $style->getNumberFormat()->setFormatCode(self::TYPES[$type]['format']);
        self::align($style->getAlignment(), self::TYPES[$type]['align']);
    }

    /** Alignement avec une marge intérieure, pour que deux colonnes voisines ne se touchent pas. */
    private static function align(Alignment $a, string $horizontal): void
    {
        $a->setHorizontal($horizontal)->setIndent(Alignment::HORIZONTAL_CENTER === $horizontal ? 0 : 1);
    }

    private function write(Spreadsheet $book): string
    {
        $book->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /** Texte du pied de page : « & » est un code de mise en forme Excel. */
    private static function footer(string $s): string
    {
        return str_replace('&', '&&', $s);
    }

    private static function number(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
    }

    /** « 9h05 », « 09:05 » → minutes ; heure absente en dernier. */
    private static function minutes(string $t): int
    {
        return preg_match('/^(\d{1,2})\s*[h:]\s*(\d{0,2})/i', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : 1440;
    }
}
