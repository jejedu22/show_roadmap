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
 * Chaque onglet est un tableau mis en forme aux couleurs de l'application : bandeau de titre (spectacle, lieu, dates),
 * en-têtes colorés, montants en euros, vraies dates Excel, ligne de total, volets figés, filtres et mise en page d'impression.
 */
class XlsxExporter
{
    // Couleurs de l'application (style.css, PDF)
    private const VIOLET = '5B2EFF';
    private const VIOLET_SOFT = 'EFEAFF';
    private const STRIPE = 'FAF8FF';
    private const LINE = 'E4DEFA';
    private const SUN = 'FFC93C';
    private const INK = '1B1B3A';
    private const MUTED = '6B6B85';
    private const CORAL = 'FF5A5F';
    private const MINT = '00C2A8';

    private const MONEY = '#,##0.00\ "€";[Red]\-#,##0.00\ "€"';
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

    public function __construct(private QuoteCalculator $quotes)
    {
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
            ['total' => 'Total', 'tab' => self::MINT]);

        $this->table($book, $ev, 'Défraiements', [['Nom', 'text', 22], ['Repas', 'int'], ['Petits-déj.', 'int', 11], ['Défraiements', 'money', 14]],
            array_map(fn ($c) => [$c['name'], $c['meals'], $c['breakfasts'], $c['perDiem']], $this->quotes->personCosts($ev)),
            ['total' => 'Total', 'tab' => self::SUN]);

        $this->travelSheets($book, $ev);

        $withContacts && $this->table($book, $ev, 'Coordonnées', [['Nom', 'text', 22], ['Fonction', 'text', 20], ['Téléphone', 'text', 16],
            ['E-mail', 'text', 30], ['Régime', 'text', 22]],
            $ev->participations->map(fn ($p) => [$p->person->fullName(), $p->role, $p->person->phone, $p->person->email, $p->person->diet])->getValues(),
            ['tab' => self::INK]);

        return $this->write($book);
    }

    /**
     * Tout ce qui touche aux coûts d'une date, sans coordonnées ni rooming : le fichier envoyé par le lien de partage des coûts.
     * Devis, tarifs, coûts par personne, défraiements jour par jour, billets, dépenses et remboursements, camion, frais supplémentaires.
     */
    public function exportCosts(Event $ev): string
    {
        $book = $this->book($ev, 'Coûts');
        $this->quoteSheet($book, $ev, true);

        $this->table($book, $ev, 'Tarifs', [['Tarif', 'text', 30], ['Valeur', 'rate', 14]], [
            ['Cachet HT', (float) $ev->fee], ['Repas / personne / jour', (float) $ev->mealRate], ['Petit-déjeuner', (float) $ev->breakfastRate],
            ['Nuitée', (float) $ev->nightRate], ['Camion (HT / km)', (float) $ev->truckRate], ['TVA', [(float) $ev->vatRate, 'percent']],
        ], ['tab' => self::VIOLET, 'filter' => false]);

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
            ['total' => 'Total', 'tab' => self::SUN, 'emphasis' => 11]);

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->days as $d) {
                if ($d->meals || $d->breakfast || $d->night) {
                    $rows[] = [$p->person->fullName(), $d->day, $d->meals, $d->breakfast, $d->night,
                        round($d->meals * (float) $ev->mealRate + $d->breakfast * (float) $ev->breakfastRate, 2)];
                }
            }
        }
        $this->table($book, $ev, 'Défraiements', [['Nom', 'text', 22], ['Date', 'date'], ['Repas', 'int'], ['Petits-déj.', 'int', 11],
            ['Nuitée', 'int'], ['Défraiement', 'money', 14]], $rows, ['total' => 'Total', 'tab' => self::SUN]);

        $this->travelSheets($book, $ev, true);

        $this->table($book, $ev, 'Transport décor', [['Trajet', 'text', 32], ['Km', 'km'], ['Tarif HT / km', 'rate', 14], ['Montant HT', 'money', 14]],
            $ev->trucks->map(fn ($t) => [$t->label, (float) $t->km, (float) $ev->truckRate, round((float) $t->km * (float) $ev->truckRate, 2)])->getValues(),
            ['total' => 'Total', 'tab' => self::INK]);
        $this->table($book, $ev, 'Frais supplémentaires', [['Libellé', 'text', 32], ['Poste', 'text', 18], ['Montant HT', 'money', 14]],
            $ev->extras->map(fn ($x) => [$x->label, 'team_transport' === $x->kind ? 'Transport équipe' : 'Autres frais', (float) $x->amount])->getValues(),
            ['total' => 'Total', 'tab' => self::INK]);

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
            ['styles' => $styles, 'tab' => self::VIOLET, 'filter' => false, 'stripes' => false]);
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
                $rows[] = [$p->person->fullName(), 'return' === $l->direction ? 'Retour' : 'Aller', $l->day,
                    $l->origin, $l->destination, $l->depTime, $l->arrTime, $l->mode, $l->reference, (float) $l->price, $l->bookingUrl];
            }
        }
        $this->table($book, $ev, 'Voyages', [['Nom', 'text', 22], ['Sens', 'text', 8], ['Date', 'date'], ['De', 'text', 18], ['À', 'text', 18],
            ['Départ', 'text', 8], ['Arrivée', 'text', 8], ['Mode', 'text', 12], ['Référence', 'text', 12], ['Prix', 'money', 12], ['Billet', 'url']],
            $rows, ['total' => 'Total', 'tab' => self::CORAL, 'direction' => 1]);

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->expenses as $x) {
                $rows[] = [$p->person->fullName(), 'return' === $x->direction ? 'Retour' : 'Aller', $x->day, $x->kind, (float) $x->amount, $x->comment];
            }
        }
        ($rows || $always) && $this->table($book, $ev, 'Dépenses trajets', [['Nom', 'text', 22], ['Sens', 'text', 8], ['Date', 'date'],
            ['Type', 'text', 22], ['Montant', 'money', 13], ['Commentaire', 'text', 36]], $rows,
            ['total' => 'Total', 'tab' => self::CORAL, 'direction' => 1, 'note' => 'Montant négatif = remboursement.']);
    }

    private function book(Event $ev, string $kind): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $book->getProperties()->setCreator('Show Roadmap')->setTitle($kind.' — '.$ev->show->title.' — '.$ev->venue)
            ->setSubject($ev->show->title)->setCompany($ev->show->company->name);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10)->getColor()->setRGB(self::INK);

        return $book;
    }

    /**
     * Un onglet : bandeau (titre, spectacle, lieu, dates), en-têtes, lignes, ligne de total.
     *
     * @param list<array{0: string|float, 1: string, 2?: int, 3?: string}> $columns [libellé, type, largeur, format de l'en-tête]
     * @param list<list<mixed>>                                            $rows    une valeur peut être [valeur, type] pour changer le type d'une cellule
     * @param array{total?: string, tab?: string, styles?: array<int, string>, filter?: bool, stripes?: bool, emphasis?: int, direction?: int, note?: string} $opts
     */
    private function table(Spreadsheet $book, Event $ev, string $title, array $columns, array $rows, array $opts = []): void
    {
        $ws = $book->createSheet();
        $ws->setTitle($title);
        $ws->getTabColor()->setRGB($opts['tab'] ?? self::VIOLET);
        $ws->setShowGridlines(false);
        $n = count($columns);
        $last = Coordinate::stringFromColumnIndex($n);
        $h = self::HEADER_ROW;

        // Bandeau
        $ws->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::VIOLET);
        $ws->getRowDimension(1)->setRowHeight(24);
        $period = $ev->firstDate->format('d/m/Y').($ev->lastDate != $ev->firstDate ? ' au '.$ev->lastDate->format('d/m/Y') : '');
        $ws->setCellValueExplicit('A2', implode(' · ', array_filter([$ev->show->title, trim($ev->venue.($ev->city ? ', '.$ev->city : '')), $period])), DataType::TYPE_STRING);
        $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB(self::MUTED);
        if (isset($opts['note'])) {
            $ws->setCellValueExplicit('A3', $opts['note'], DataType::TYPE_STRING);
            $ws->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::MUTED);
        }

        // En-têtes
        foreach ($columns as $c => $col) {
            $cell = [$c + 1, $h];
            is_float($col[0]) ? $ws->setCellValueExplicit($cell, $col[0], DataType::TYPE_NUMERIC) : $ws->setCellValueExplicit($cell, $col[0], DataType::TYPE_STRING);
            isset($col[3]) && $ws->getStyle($cell)->getNumberFormat()->setFormatCode($col[3]);
            $ws->getColumnDimensionByColumn($c + 1)->setWidth($col[2] ?? self::TYPES[$col[1]]['width']);
        }
        $head = $ws->getStyle("A$h:$last$h");
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VIOLET);
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension($h)->setRowHeight(28);
        foreach ($columns as $c => $col) {
            self::align($ws->getStyle([$c + 1, $h])->getAlignment(), self::TYPES[$col[1]]['align']);
        }

        // Lignes
        $r = $h;
        foreach ($rows as $i => $row) {
            ++$r;
            foreach (array_values($row) as $c => $v) {
                [$v, $type] = is_array($v) ? $v : [$v, $columns[$c][1]];
                $this->cell($ws, $c + 1, $r, $v, $type);
            }
            $style = $opts['styles'][$i] ?? null;
            $range = $ws->getStyle("A$r:$last$r");
            $range->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LINE);
            $range->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            if ('subtotal' === $style) {
                $range->getFont()->setBold(true);
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::VIOLET_SOFT);
            } elseif ('grand' === $style) {
                $range->getFont()->setBold(true)->setSize(12);
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SUN);
                $ws->getRowDimension($r)->setRowHeight(22);
            } elseif ('note' === $style) {
                $range->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
                $range->getAlignment()->setIndent(1);
            } elseif (($opts['stripes'] ?? true) && 1 === $i % 2) {
                $range->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::STRIPE);
            }
            if (isset($opts['direction'])) {
                $ws->getStyle([$opts['direction'] + 1, $r])->getFont()->setBold(true)
                    ->getColor()->setRGB('Retour' === $row[$opts['direction']] ? 'D93A3F' : self::VIOLET);
            }
            if (isset($opts['emphasis'])) {
                $ws->getStyle([$opts['emphasis'] + 1, $r])->getFont()->setBold(true);
            }
        }
        if (!$rows) {
            ++$r;
            $ws->setCellValueExplicit("A$r", 'Aucune ligne', DataType::TYPE_STRING);
            $this->format($ws, 1, $r, 'text');
            $ws->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        }

        // Total : formules SOMME, pour rester juste si l'on modifie le fichier
        if (isset($opts['total']) && $rows) {
            $first = $h + 1;
            $end = $r;
            ++$r;
            $ws->setCellValueExplicit("A$r", $opts['total'], DataType::TYPE_STRING);
            $this->format($ws, 1, $r, 'text');
            foreach ($columns as $c => $col) {
                if (self::TYPES[$col[1]]['sum'] && $c > 0) {
                    $letter = Coordinate::stringFromColumnIndex($c + 1);
                    $ws->setCellValueExplicit([$c + 1, $r], "=SUM($letter$first:$letter$end)", DataType::TYPE_FORMULA);
                    $this->format($ws, $c + 1, $r, $col[1]);
                }
            }
            $total = $ws->getStyle("A$r:$last$r");
            $total->getFont()->setBold(true);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SUN);
            $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::INK);
            $ws->getRowDimension($r)->setRowHeight(20);
        }

        // Navigation et impression
        $ws->freezePane('B'.($h + 1));
        if (($opts['filter'] ?? true) && $rows) {
            $ws->setAutoFilter("A$h:$last".($h + count($rows)));
        }
        $setup = $ws->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($n > 6 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($h, $h);
        $ws->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        $ws->getHeaderFooter()->setOddFooter('&L&8 '.self::footer($ev->show->title.' — '.$ev->venue).'&R&8 Page &P / &N');
        $ws->setSelectedCell('A'.($h + 1));
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
                $ws->getStyle([$col, $row])->getFont()->setUnderline(true)->getColor()->setRGB(self::VIOLET);
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
