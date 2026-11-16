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

    public function __construct(private QuoteCalculator $quotes, private Themes $themes, private Presenter $presenter)
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
        $quote = $this->quoteSheet($book, $ev, false);

        // Rooming : une ligne par chambre (une chambre partagée — double, twin, appartement — réunit ses occupants)
        $dates = $ev->spanDates();
        $rows = [];
        $nightsCol = 5 + count($dates);
        $sumDates = $dates ? new XlsxFormula('=SUM(E{r}:'.Coordinate::stringFromColumnIndex($nightsCol - 1).'{r})') : 0;
        foreach (Rooming::rooms($ev) as $room) {
            $rows[] = [implode(' + ', array_map(fn ($o) => $o->person->fullName(), $room['occupants'])),
                implode(' / ', array_filter(array_map(fn ($o) => $o->role, $room['occupants']))), $room['type'].(count($room['occupants']) > 1 ? ' (partagée)' : ''),
                $room['lodging'], ...array_map(fn ($d) => (int) $room['nights'][$d->format('Y-m-d')], $dates), $sumDates, $room['warning']];
        }
        $rooming = $this->table($book, $ev, 'Rooming', [['Occupant(s)', 'text', 30], ['Fonction', 'text', 22], ['Chambre', 'text', 18], ['Hébergement', 'text', 24],
            ...array_map(fn ($d) => [ExcelDate::PHPToExcel($d), 'int', 11, '[$-40C]ddd dd/mm'], $dates), ['Nuits', 'int'], ['À vérifier', 'text', 30]], $rows,
            ['total' => 'Chambres', 'tab' => $this->rgb('mint')]);

        $perDiem = $this->perDiemSheet($book, $ev);
        $travel = $this->travelSheet($book, $ev);
        // Devis relié aux onglets : il se recalcule si l'on y change un prix, un nombre de repas ou de nuits
        $nights = $rooming['total'] ? self::ref('Rooming', $nightsCol, $rooming['total']).'*'.self::number((float) $ev->nightRate, '.') : null;
        $this->linkQuote($book, $ev, $quote, $perDiem, $travel, $nights);

        $this->transfersSheet($book, $ev, $withContacts);
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
     * Totaux et montants calculés par formules, reliés entre onglets.
     */
    public function exportCosts(Event $ev): string
    {
        $book = $this->book($ev, 'Coûts');
        $quote = $this->quoteSheet($book, $ev, true, self::ref('Tarifs', 2, self::HEADER_ROW + 7));

        $this->table($book, $ev, 'Tarifs', [['Tarif', 'text', 30], ['Valeur', 'rate', 14]], [
            ['Cachet HT', (float) $ev->fee], ['Repas / personne / jour', (float) $ev->mealRate], ['Petit-déjeuner', (float) $ev->breakfastRate],
            ['Nuitée', (float) $ev->nightRate], ['Camion (HT / km)', (float) $ev->truckRate], ['Budget transport (HT)', (float) $ev->transportBudget], ['TVA', [(float) $ev->vatRate, 'percent']],
        ], ['tab' => $this->rgb('violet'), 'filter' => false]);

        $perDiem = $this->perDiemSheet($book, $ev);
        $travel = $this->travelSheet($book, $ev);
        $this->linkQuote($book, $ev, $quote, $perDiem, $travel, null);

        // Par personne : chaque montant renvoie aux onglets Défraiements et Voyages (onglet placé après Tarifs)
        $rows = [];
        foreach (array_values($ev->participations->toArray()) as $i => $p) { // même ordre que les grilles de Défraiements
            $d = $perDiem['people'][$i];
            $t = $travel['people'][$p->id] ?? [];
            $f = fn (?array $refs) => null === $refs ? 0 : ($refs ? new XlsxFormula('='.implode('+', $refs)) : 0);
            $rows[] = [$p->person->fullName(), $p->role, $p->departureCity,
                new XlsxFormula('='.$d['meals']['n']), new XlsxFormula('='.$d['breakfast']['n']), new XlsxFormula('='.$d['night']['n']),
                new XlsxFormula('='.$d['meals']['amount'].'+'.$d['breakfast']['amount']),
                $f($t['outbound'] ?? []), $f($t['return'] ?? []), $f($t['expenses'] ?? []),
                new XlsxFormula('=H{r}+I{r}+J{r}'), new XlsxFormula('=G{r}+K{r}')];
        }
        $this->table($book, $ev, 'Par personne', [['Nom', 'text', 22], ['Fonction', 'text', 18], ['Départ de', 'text', 14], ['Repas', 'int'],
            ['Petits-déj.', 'int', 11], ['Nuitées', 'int'], ['Défraiements', 'money', 14], ['Billets aller', 'money', 14], ['Billets retour', 'money', 14],
            ['Dépenses / remb.', 'money', 16], ['Total voyages', 'money', 14], ['Total', 'money', 14]], $rows,
            ['total' => 'Total', 'tab' => $this->rgb('sun'), 'emphasis' => 11]);
        $book->setIndexByName('Par personne', 2);

        return $this->write($book);
    }

    /** Arrivées et départs de l'équipe (transferts) : un classeur d'un onglet ; $withPhone : droit « Voir les coordonnées ». */
    public function exportTransfers(Event $ev, bool $withPhone): string
    {
        $book = $this->book($ev, 'Arrivées et départs');
        $this->transfersSheet($book, $ev, $withPhone);

        return $this->write($book);
    }

    public static function transfersFilename(Event $ev): string
    {
        return str_replace([' ', '/', '\''], ['_', '-', ''], 'Arrivees_departs_'.$ev->show->title.'_'.$ev->venue).'.xlsx';
    }

    /** Onglet Arrivées et départs : une ligne par personne, dans l'ordre des arrivées (voir Transfers). */
    private function transfersSheet(Spreadsheet $book, Event $ev, bool $withPhone): void
    {
        $rows = [];
        foreach (Transfers::rows($this->presenter->event($ev)) as $t) {
            [$a, $d] = [$t['arrival'], $t['departure']];
            $date = fn (?array $x) => $x ? \DateTimeImmutable::createFromFormat('!Y-m-d', $x['day']) : '';
            $rows[] = [$t['name'], $t['role'], ...($withPhone ? [$t['phone']] : []),
                $date($a), $a['time'] ?? '', $a ? $a['place'] : 'Aller à réserver',
                $date($d), $d['time'] ?? '', $d ? $d['place'] : 'Retour à réserver'];
        }
        $this->table($book, $ev, 'Arrivées et départs', [['Nom', 'text', 22], ['Fonction', 'text', 18], ...($withPhone ? [['Téléphone', 'text', 15]] : []),
            ['Arrivée le', 'date', 16], ['Heure d\'arrivée', 'text', 10], ['Lieu d\'arrivée', 'text', 20],
            ['Départ le', 'date', 16], ['Heure de départ', 'text', 10], ['Lieu de départ', 'text', 20]], $rows,
            ['tab' => $this->rgb('coral'), 'note' => 'Pour organiser les transferts : arrivée du dernier trajet aller, départ du premier trajet retour.']);
    }

    public static function costsFilename(Event $ev): string
    {
        return str_replace([' ', '/', '\''], ['_', '-', ''], 'Couts_'.$ev->show->title.'_'.$ev->venue).'.xlsx';
    }

    /**
     * Devis : postes, sous-totaux, TVA, total TTC mis en avant ; $detail : part des billets et des dépenses de trajet.
     * Sous-totaux, TVA et total TTC en formules ; $vatRef : cellule du taux de TVA (onglet Tarifs), sinon taux écrit dans la formule.
     *
     * @return array{rows: array<string, int>, dont: array<string, int>} lignes de la feuille des postes et des lignes « dont »
     */
    private function quoteSheet(Spreadsheet $book, Event $ev, bool $detail, ?string $vatRef = null): array
    {
        $q = $this->quotes->compute($ev);
        $first = self::HEADER_ROW + 1; // ligne du cachet
        $rows = [['Cachet', $q['fee']]];
        $at = [];
        foreach ($q['lines'] as $l) {
            $at[$l['key']] = $first + count($rows);
            $rows[] = [$l['label'], $l['amount']];
        }
        $annex = $first + count($rows);
        $styles = [];
        $styles[count($rows)] = 'subtotal';
        $rows[] = ['Total frais annexes', new XlsxFormula(sprintf('=SUM(B%d:B%d)', $first + 1, $annex - 1))];
        $styles[count($rows)] = 'subtotal';
        $rows[] = ['Total HT', new XlsxFormula(sprintf('=B%d+B%d', $first, $annex))];
        $rows[] = ['TVA '.self::number((float) $ev->vatRate).' %', new XlsxFormula(sprintf('=ROUND(B%d*%s/100,2)', $annex + 1, $vatRef ?? self::number((float) $ev->vatRate, '.')))];
        $styles[count($rows)] = 'grand';
        $rows[] = ['Total TTC', new XlsxFormula(sprintf('=B%d+B%d', $annex + 1, $annex + 2))];
        $dont = [];
        if ($detail) {
            $dont = ['tickets' => $first + count($rows), 'expenses' => $first + count($rows) + 1];
            $styles[count($rows)] = 'note';
            $rows[] = ['dont billets (train, avion…)', $q['travelTickets']];
            $styles[count($rows)] = 'note';
            $rows[] = ['dont dépenses et remboursements de trajet', $q['travelExpenses']];
        }
        $this->table($book, $ev, 'Devis', [['Poste', 'text', 52], ['Montant', 'rate', 16]], $rows,
            ['styles' => $styles, 'tab' => $this->rgb('violet'), 'filter' => false, 'stripes' => false]);

        return ['rows' => $at, 'dont' => $dont];
    }

    /**
     * Postes du devis remplacés par des renvois aux onglets détaillés : transport équipe (total des voyages + transports
     * locaux), repas et petits-déjeuners (Défraiements), nuitées par chambre ($nights, export complet), lignes « dont ».
     */
    private function linkQuote(Spreadsheet $book, Event $ev, array $quote, array $perDiem, array $travel, ?string $nights): void
    {
        $ws = $book->getSheetByName('Devis');
        $q = $this->quotes->compute($ev);
        $set = fn (int $row, string $formula) => $ws->setCellValueExplicit([2, $row], $formula, DataType::TYPE_FORMULA);
        $team = $travel['grand'].($q['teamExtras'] ? '+'.self::number($q['teamExtras'], '.') : '');
        isset($quote['rows']['team']) && $set($quote['rows']['team'], '='.$team);
        foreach (['meals' => 'meals', 'breakfasts' => 'breakfast'] as $key => $field) {
            isset($quote['rows'][$key], $perDiem['totals'][$field]) && $set($quote['rows'][$key], '='.$perDiem['totals'][$field]);
        }
        null !== $nights && isset($quote['rows']['nights']) && $set($quote['rows']['nights'], '='.$nights);
        if ($quote['dont']) {
            $set($quote['dont']['tickets'], '='.($travel['tickets'] ?? '0'));
            $set($quote['dont']['expenses'], '='.($travel['expenses'] ?? '0'));
        }
    }

    /**
     * Onglet Voyages : billets, puis dépenses et remboursements, alignés sur les mêmes colonnes (montants dans la colonne Prix),
     * et total général des voyages.
     *
     * @return array{people: array<int, array{outbound: list<string>, return: list<string>, expenses: list<string>}>, tickets: ?string, expenses: ?string, grand: string}
     *                cellules des montants par participation, des totaux et du total général (pour les formules des autres onglets)
     */
    private function travelSheet(Spreadsheet $book, Event $ev): array
    {
        $ws = $this->newSheet($book, $ev, 'Voyages', $this->rgb('coral'));
        $rows = $owners = [];
        foreach ($ev->participations as $p) {
            // Allers puis retours, chacun dans l'ordre chronologique
            $legs = $p->legs->toArray();
            usort($legs, fn ($a, $b) => [$a->direction !== 'outbound', $a->day, self::minutes($a->depTime)]
                <=> [$b->direction !== 'outbound', $b->day, self::minutes($b->depTime)]);
            foreach ($legs as $l) {
                $owners[] = [$p->id, 'return' === $l->direction ? 'return' : 'outbound'];
                $rows[] = [$p->person->fullName(), 'return' === $l->direction ? 'Retour' : 'Aller', $l->day,
                    $l->origin, $l->destination, $l->depTime, $l->arrTime, $l->mode, ['1' => '1re', '2' => '2nde'][$l->travelClass] ?? '',
                    $l->reference, (float) $l->price, $l->bookingUrl];
            }
        }
        $tickets = $this->block($ws, self::HEADER_ROW, [['Nom', 'text', 22], ['Sens', 'text', 8], ['Date', 'date'], ['De', 'text', 18], ['À', 'text', 18],
            ['Départ', 'text', 8], ['Arrivée', 'text', 8], ['Mode', 'text', 12], ['Classe', 'text', 8], ['Référence', 'text', 12], ['Prix', 'money', 12], ['Billet', 'url']],
            $rows, ['heading' => 'Billets', 'total' => 'Total billets', 'direction' => 1]);
        $people = [];
        foreach ($owners as $i => [$pid, $dir]) {
            $people[$pid][$dir][] = self::ref('Voyages', 11, $tickets['first'] + $i);
        }

        $rows = [];
        foreach ($ev->participations as $p) {
            foreach ($p->expenses as $x) {
                $people[$p->id]['expenses'][] = self::ref('Voyages', 11, $tickets['next'] + 2 + count($rows)); // intitulé, en-tête, puis lignes
                $rows[] = [$p->person->fullName(), 'return' === $x->direction ? 'Retour' : 'Aller', $x->day, $x->kind, $x->comment, (float) $x->amount, ''];
            }
        }
        // Type sous « De », commentaire sur « À » → « Référence », montant sous « Prix »
        $expenses = $this->block($ws, $tickets['next'], [['Nom', 'text'], ['Sens', 'text'], ['Date', 'date'], ['Type', 'text'],
            ['Commentaire', 'text', null, null, 6], ['Montant', 'money'], ['', 'text']], $rows,
            ['heading' => 'Dépenses et remboursements (montant négatif = remboursement)', 'total' => 'Total dépenses et remboursements',
                'direction' => 1, 'widths' => false]);

        $price = Coordinate::stringFromColumnIndex(11);
        $sum = array_filter([$tickets['total'] ? $price.$tickets['total'] : null, $expenses['total'] ? $price.$expenses['total'] : null]);
        $this->grandTotal($ws, $expenses['next'], 12, 'Total voyages', [11 => '='.($sum ? implode('+', $sum) : '0')]);
        $this->finish($ws, $ev, 12);

        return ['people' => $people, 'tickets' => $tickets['total'] ? self::ref('Voyages', 11, $tickets['total']) : null,
            'expenses' => $expenses['total'] ? self::ref('Voyages', 11, $expenses['total']) : null, 'grand' => self::ref('Voyages', 11, $expenses['next'])];
    }

    /**
     * Onglet Défraiements, comme le tableau de l'onglet Séjour : une grille personnes × jours pour les repas, les petits-déjeuners
     * et les nuitées, avec total, tarif et montant par personne (formules), puis le total général.
     *
     * @return array{people: list<array<string, array{n: string, amount: string}>>, totals: array<string, string>} cellules par
     *                participation (dans l'ordre de l'équipe) et des montants totaux, par grille (meals, breakfast, night)
     */
    private function perDiemSheet(Spreadsheet $book, Event $ev): array
    {
        $ws = $this->newSheet($book, $ev, 'Défraiements', $this->rgb('sun'));
        $dates = $ev->spanDates();
        $r = self::HEADER_ROW;
        $amounts = $people = $totals = [];
        $width = 0;
        $n = count($dates);
        [$nCol, $rateCol, $amountCol] = [$n + 2, $n + 3, $n + 4];
        $L = fn (int $c) => Coordinate::stringFromColumnIndex($c);
        $count = $n ? new XlsxFormula('=SUM(B{r}:'.$L($n + 1).'{r})') : 0;
        $amount = new XlsxFormula('='.$L($nCol).'{r}*'.$L($rateCol).'{r}');
        foreach ([['meals', 'Repas', (float) $ev->mealRate], ['breakfast', 'Petits-déjeuners', (float) $ev->breakfastRate],
            ['night', 'Nuitées', (float) $ev->nightRate]] as $i => [$field, $label, $rate]) {
            $rows = [];
            foreach ($ev->participations as $p) {
                $rows[] = [$p->person->fullName(), ...array_map(fn ($d) => $p->day($d)?->$field ?? 0, $dates), $count, $rate, $amount];
            }
            $b = $this->block($ws, $r, [['Nom', 'text', 22], ...array_map(fn ($d) => [ExcelDate::PHPToExcel($d), 'int', 10, '[$-40C]ddd dd/mm'], $dates),
                ['Total', 'int'], ['Tarif', 'rate', 12], ['Montant', 'money', 14]], $rows,
                ['heading' => $label, 'total' => 'Total '.mb_strtolower($label), 'emphasis' => $n + 1, 'widths' => 0 === $i]);
            foreach (array_keys($rows) as $k) {
                $people[$k][$field] = ['n' => self::ref('Défraiements', $nCol, $b['first'] + $k), 'amount' => self::ref('Défraiements', $amountCol, $b['first'] + $k)];
            }
            $width = $b['width'];
            if ($b['total']) {
                $amounts[] = $L($width).$b['total'];
                $totals[$field] = self::ref('Défraiements', $amountCol, $b['total']);
            }
            $r = $b['next'];
        }
        $this->grandTotal($ws, $r, $width, 'Total défraiements (repas, petits-déjeuners, nuitées)', [$width => '='.($amounts ? implode('+', $amounts) : '0')]);
        $this->finish($ws, $ev, $width);

        return ['people' => $people, 'totals' => $totals];
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
                        \App\Entity\Invitation::TICKETS[$i->ticket] ?? 'Invitation', \App\Entity\Invitation::KINDS[$i->kind], $i->lastName, $i->firstName, $i->structure, $i->places];
                }
            }
        }
        $ws = $this->newSheet($book, $ev, 'Invitations', $this->rgb('mint'),
            sprintf('Invitations : %d mises à disposition · %d attribuées (Cie %d, Pro %d) · %d restantes — Détaxes : %d prévues · %d attribuées · %d restantes',
                $sum['quota'], $sum['used'], $sum['company'], $sum['pro'], $sum['left'], $sum['detaxeQuota'], $sum['detaxe'], $sum['detaxeLeft']));
        $b = $this->block($ws, self::HEADER_ROW, [['Date', 'date'], ['Représentation', 'text', 22], ['Billet', 'text', 11], ['Type', 'text', 8], ['Nom', 'text', 20], ['Prénom', 'text', 16],
            ['Structure', 'text', 28], ['Places', 'int']], $rows, ['total' => 'Total']);
        $rows && $ws->setAutoFilter('A'.self::HEADER_ROW.':H'.$b['last']);
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

    /** Un onglet d'un seul tableau : bandeau, tableau, filtres, volets figés, impression ; renvoie les lignes utilisées (voir block). */
    private function table(Spreadsheet $book, Event $ev, string $title, array $columns, array $rows, array $opts = []): array
    {
        $ws = $this->newSheet($book, $ev, $title, $opts['tab'] ?? $this->rgb('violet'), $opts['note'] ?? null);
        $b = $this->block($ws, self::HEADER_ROW, $columns, $rows, $opts);
        if (($opts['filter'] ?? true) && $rows) {
            $ws->setAutoFilter('A'.self::HEADER_ROW.':'.Coordinate::stringFromColumnIndex($b['width']).($b['last']));
        }
        $this->finish($ws, $ev, $b['width'], self::HEADER_ROW);

        return $b;
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
        if ($v instanceof XlsxFormula) {
            // Formule écrite par l'export ({r} : ligne de la cellule) ; jamais une saisie (voir plus bas)
            $ws->setCellValueExplicit([$col, $row], str_replace('{r}', (string) $row, $v->formula), DataType::TYPE_FORMULA);
        } elseif ($v instanceof \DateTimeInterface) {
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

    /** « 20 », « 5,5 » ; $sep « . » pour un nombre dans une formule. */
    private static function number(float $x, string $sep = ','): string
    {
        return rtrim(rtrim(number_format($x, 2, $sep, ''), '0'), $sep);
    }

    /** Cellule d'un autre onglet pour une formule : 'Onglet'!K12. */
    private static function ref(string $sheet, int $col, int $row): string
    {
        return "'".str_replace("'", "''", $sheet)."'!".Coordinate::stringFromColumnIndex($col).$row;
    }

    /** « 9h05 », « 09:05 » → minutes ; heure absente en dernier. */
    private static function minutes(string $t): int
    {
        return preg_match('/^(\d{1,2})\s*[h:]\s*(\d{0,2})/i', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : 1440;
    }
}
