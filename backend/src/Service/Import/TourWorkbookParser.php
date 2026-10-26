<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Lit un classeur « Devis_<Spectacle>_<Ville>.xlsx » et en tire un brouillon de date (tableau JSON-isable) :
 * en-tête et grilles de l'onglet DEVIS, coordonnées, feuille de route (trajets, hébergements, rubriques, planning).
 * Rien n'est écrit en base ici ; les incohérences repérées sont listées dans « warnings ».
 */
class TourWorkbookParser
{
    private const MONTHS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12];
    private const LODGING_WORDS = ['hotel', 'appartement', 'residence', 'gite', 'logement', 'maison', 'auberge', 'chambre', 'studio', 'hebergement'];

    public function parse(string $path, string $filename): array
    {
        $reader = new Xlsx();
        $reader->setReadEmptyCells(false);
        $wb = $reader->load($path);

        $draft = [
            'file' => $filename,
            'fileCity' => self::cityFromFilename($filename),
            'show' => '',
            'event' => [
                'venue' => '', 'city' => '', 'address' => '', 'venueContact' => '', 'firstDate' => null, 'lastDate' => null,
                'techArrival' => null, 'artisticArrival' => null, 'departure' => null, 'performances' => 1,
                'fee' => null, 'mealRate' => null, 'breakfastRate' => null, 'truckRate' => null, 'vatRate' => null,
            ],
            'team' => [], 'legs' => [], 'lodgings' => [], 'sections' => [], 'schedule' => [], 'trucks' => [],
            'roadmap' => ['found' => false, 'mismatch' => false, 'header' => ''],
            'warnings' => [],
        ];

        $devis = $this->sheet($wb, 'devis');
        if (!$devis) {
            throw new \InvalidArgumentException('Onglet DEVIS introuvable : ce classeur ne suit pas le modèle attendu.');
        }
        $this->parseDevis($devis, $draft);
        if ($coord = $this->sheet($wb, 'coordonnees')) {
            $this->parseContacts($coord, $draft);
        }
        if ($fdr = $this->sheet($wb, 'feuille de route')) {
            $this->parseRoadmap($fdr, $draft);
        }
        $wb->disconnectWorksheets(); // libère la mémoire avant le classeur suivant
        $this->finish($draft);

        return $draft;
    }

    // ───────────────────────── DEVIS ─────────────────────────

    private function parseDevis(Sheet $s, array &$d): void
    {
        $e = &$d['event'];
        $label = fn (string $l) => ($p = $s->find($l, 1, 1)) && $p[1] < 15 ? $p[1] : null;

        if ($r = $label('Spectacle')) {
            $d['show'] = self::sentence($s->text(2, $r));
        }
        if ($r = $label('Ville')) {
            $head = $s->text(2, $r);
            $parts = preg_split('/\s+[-–]\s+/u', $head);
            if (count($parts) > 1) {
                $e['city'] = self::title(array_pop($parts));
                $head = implode(' - ', $parts);
            }
            $e['venue'] = self::title($head);
            $d['roadmap']['devisVenue'] = $head;
        }
        $dates = [];
        if ($r = $label('Dates')) {
            $dates = ($dt = $s->date(2, $r)) ? [$dt] : self::frenchDates($s->text(2, $r));
        }
        foreach (['techArrival' => 'Arrivée équipe tech', 'artisticArrival' => 'Arrivée équipe art', 'departure' => 'Départ équipe'] as $k => $l) {
            if ($r = $label($l)) {
                $e[$k] = $s->date(2, $r);
            }
        }

        // Distribution + frais de voyage (mêmes lignes, colonnes A/B et O…).
        $team = [];
        if ($h = $s->find('Nom Prénom', 1, 1)) {
            $hr = $h[1];
            $cityCol = $s->find('Ville de départ', $hr, null, 40);
            $cityCol = $cityCol && $cityCol[1] === $hr ? $cityCol[0] : null;
            $outCol = $retCol = null;
            for ($c = 1; $c <= 40; ++$c) {
                $t = Sheet::norm($s->text($c, $hr));
                if (null === $outCol && str_starts_with($t, 'aller')) {
                    $outCol = $c;
                } elseif (null === $retCol && str_contains($t, 'home')) {
                    $retCol = $c;
                }
            }
            for ($r = $hr + 1; $r < $hr + 60 && '' !== ($name = $s->text(1, $r)); ++$r) {
                [$last, $first] = self::splitName($name);
                $note = '';
                if ($retCol) {
                    for ($c = $retCol + 1; $c <= $retCol + 3; ++$c) {
                        $t = $s->text($c, $r);
                        if ('' !== $t && null === $s->number($c, $r)) {
                            $note = trim($note.' '.$t.(($n = $s->number($c + 1, $r)) ? ' '.self::num($n).' €' : ''));
                            break;
                        }
                    }
                }
                $team[] = [
                    'key' => 'p'.count($team), 'lastName' => $last, 'firstName' => $first, 'role' => $s->text(2, $r),
                    'roomType' => 'single', 'departureCity' => $cityCol ? $s->text($cityCol, $r) : '',
                    'outboundPrice' => $outCol ? (float) $s->number($outCol, $r) : 0.0,
                    'returnPrice' => $retCol ? (float) $s->number($retCol, $r) : 0.0,
                    'note' => mb_substr($note, 0, 255), 'phone' => '', 'email' => '', 'diet' => '', 'days' => [], 'lodging' => null,
                ];
            }
        }
        $d['team'] = $team;

        // Rooming (A/B + dates), repas (O… + dates), petits-déjeuners.
        $rooming = $s->find('HÉBERGEMENTS', 1, 1) ?? $s->find('ROOMING', 1, 1);
        if ($rooming && ($h = $s->find('Nom Prénom', $rooming[1], 1))) {
            foreach ($this->grid($s, 1, $h[1], 3) as $name => [$row, $days]) {
                if (null !== ($i = $this->member($d['team'], $name))) {
                    $room = Sheet::norm($s->text(2, $row));
                    $d['team'][$i]['roomType'] = in_array($room, ['single', 'double', 'twin', 'appartement'], true) ? $room : 'single';
                    foreach ($days as $day => $n) {
                        $this->addDay($d['team'][$i], $day, 'night', $n);
                    }
                }
            }
        }
        $meals = $s->find('REPAS', 1, 15, 15);
        if ($meals && ($h = $s->find('Nom Prénom', $meals[1], 15, 15))) {
            $this->fillGrid($s, $d, $h[1], 'meals');
            $e['mealRate'] = $this->base($s, $meals[1] - 3, $meals[1]);
        }
        $bf = $s->find('Petits-D', 1, 15, 15);
        if ($bf && ($h = $s->find('Nom Prénom', $bf[1], 15, 15))) {
            $this->fillGrid($s, $d, $h[1], 'breakfast');
            $e['breakfastRate'] = $this->base($s, $bf[1] - 3, $bf[1]);
        }

        // Devis : cachet, TVA, camion.
        if ($p = $s->find('Cachet', 1, 1)) {
            for ($c = 2; $c <= 6 && null === $e['fee']; ++$c) {
                $e['fee'] = $s->number($c, $p[1]);
            }
            if (preg_match('/(\d+)\s*repr/u', $s->text(1, $p[1]), $m)) {
                $e['performances'] = (int) $m[1];
            }
        }
        if (($p = $s->find('Tva', 1, 1)) && preg_match('/(\d+(?:[,.]\d+)?)\s*%/', $s->text(1, $p[1]), $m)) {
            $e['vatRate'] = (float) str_replace(',', '.', $m[1]);
        }
        if ($p = $s->find('Camion', 1, 1)) {
            for ($r = $p[1] + 1; $r < $p[1] + 10 && !str_starts_with(Sheet::norm($s->text(1, $r)), 'total'); ++$r) {
                $e['truckRate'] ??= $s->number(5, $r);
                if (($km = $s->number(2, $r)) > 0) {
                    $d['trucks'][] = ['label' => $s->text(1, $r) ?: 'Transport décor', 'km' => $km];
                }
            }
        }

        // Dates des représentations : année absente (« Jeudi 22 et vendredi 23 janvier ») → déduite des grilles.
        $gridDays = $this->gridDays($d['team']);
        if ($dates && is_array($dates[0]) && !$dates[0]['year']) {
            $ref = $gridDays[0] ?? $e['artisticArrival'] ?? $e['techArrival'] ?? new \DateTimeImmutable();
            $dates = array_map(fn ($x) => ['year' => (int) $ref->format('Y')] + $x, array_map(fn ($x) => array_filter($x), $dates));
            $d['warnings'][] = self::warn('info', 'year', sprintf('Année absente des dates du devis : %d retenue d’après les grilles.', $ref->format('Y')));
        }
        $dates = array_values(array_filter(array_map(fn ($x) => $x instanceof \DateTimeImmutable ? $x
            : (\DateTimeImmutable::createFromFormat('!Y-n-j', "{$x['year']}-{$x['month']}-{$x['day']}") ?: null), $dates)));
        sort($dates);
        $e['firstDate'] = $dates[0] ?? null;
        $e['lastDate'] = $dates ? end($dates) : null;
        $e['performanceDays'] = count($dates);
    }

    /** Lit une grille nom × dates : [nom => [ligne, [Y-m-d => n]]]. */
    private function grid(Sheet $s, int $nameCol, int $headerRow, int $firstDateCol): array
    {
        $cols = [];
        for ($c = $firstDateCol; $c < $firstDateCol + 40 && ($day = $s->date($c, $headerRow)); ++$c) {
            $cols[$c] = $day->format('Y-m-d');
        }
        $out = [];
        for ($r = $headerRow + 1; $r < $headerRow + 60 && '' !== ($name = $s->text($nameCol, $r)); ++$r) {
            $days = [];
            foreach ($cols as $c => $day) {
                if (($n = (int) $s->number($c, $r)) > 0) {
                    $days[$day] = $n;
                }
            }
            $out[$name] = [$r, $days];
        }

        return $out;
    }

    private function fillGrid(Sheet $s, array &$d, int $headerRow, string $field): void
    {
        foreach ($this->grid($s, 15, $headerRow, 16) as $name => [, $days]) {
            if (null !== ($i = $this->member($d['team'], $name))) {
                foreach ($days as $day => $n) {
                    $this->addDay($d['team'][$i], $day, $field, $n);
                }
            }
        }
    }

    /** Montant « BASE : » le plus proche au-dessus d'un bloc (repas ou petits-déjeuners). */
    private function base(Sheet $s, int $from, int $to): ?float
    {
        for ($r = $to; $r >= max(1, $from - 15); --$r) {
            for ($c = 15; $c <= 30; ++$c) {
                if (str_starts_with(Sheet::norm($s->text($c, $r)), 'base')) {
                    return $s->number($c + 1, $r);
                }
            }
        }

        return null;
    }

    private function addDay(array &$person, string $day, string $field, int $n): void
    {
        $person['days'][$day] ??= ['night' => 0, 'meals' => 0, 'breakfast' => 0];
        $person['days'][$day][$field] = min($n, 'meals' === $field ? 2 : 1);
    }

    // ───────────────────────── Coordonnées ─────────────────────────

    private function parseContacts(Sheet $s, array &$d): void
    {
        if (!$h = $s->find('NOM Prénom', 1, null, 6)) {
            return;
        }
        [$c, $hr] = $h;
        for ($r = $hr + 1; $r < $hr + 80 && '' !== ($name = $s->text($c, $r)); ++$r) {
            if (null === ($i = $this->member($d['team'], $name))) {
                continue;
            }
            $p = &$d['team'][$i];
            $p['phone'] = mb_substr($s->text($c + 2, $r), 0, 60);
            $mail = $s->text($c + 3, $r);
            $p['email'] = filter_var($mail, FILTER_VALIDATE_EMAIL) ? mb_strtolower($mail) : '';
            $diet = $s->text($c + 4, $r);
            $p['diet'] = in_array(Sheet::norm($diet), ['ras', 'rien', 'aucun', '-', 'non'], true) ? '' : mb_substr($diet, 0, 255);
            '' === $p['role'] && $p['role'] = $s->text($c + 1, $r);
            unset($p);
        }
    }

    // ───────────────────────── Feuille de route ─────────────────────────

    private function parseRoadmap(Sheet $s, array &$d): void
    {
        $mode = 'header';   // header | travel | lodging | section | planning
        $direction = 'outbound';
        $day = null;
        $planningSeen = false;
        $headerLines = [];
        $who = null;        // index dans l'équipe du voyageur courant (null = inconnu)
        $whoLabel = '';
        $block = null;      // rubrique ou hébergement en cours
        $legs = $sections = $lodgings = $schedule = $unknown = [];
        $dates = [];

        $closeBlock = function () use (&$block, &$sections, &$lodgings) {
            if (!$block) {
                return;
            }
            if ('lodging' === $block['type']) {
                foreach ($block['items'] as $l) {
                    $lodgings[] = $l;
                }
                if ($block['intro']) {
                    $sections[] = ['title' => self::sectionTitle($block['title']), 'body' => implode("\n", $block['intro'])];
                }
            } elseif ($block['lines']) {
                $sections[] = ['title' => self::sectionTitle($block['title']), 'body' => implode("\n", $block['lines'])];
            }
            $block = null;
        };

        for ($r = 1; $r <= $s->maxRow(); ++$r) {
            $b = $s->value(2, $r);
            $bt = $s->text(2, $r);
            $c = $s->text(3, $r);
            if ($b instanceof \DateTimeImmutable) {
                $label = Sheet::norm($c);
                $dates[] = $b;
                if (str_contains($label, 'retour')) {
                    $closeBlock();
                    [$mode, $direction] = ['travel', 'return'];
                } elseif (str_contains($label, 'aller')) {
                    $closeBlock();
                    [$mode, $direction] = ['travel', 'outbound'];
                } elseif ('planning' !== $mode && 'travel' !== $mode) {
                    $closeBlock();
                    $mode = $planningSeen ? 'planning' : 'travel';
                }
                $day = $b->format('Y-m-d');
                $who = null;
                continue;
            }
            if ('' === $bt && '' === $c) {
                continue;
            }
            if ('header' === $mode) {
                $headerLines[] = $bt;
                continue;
            }
            if ('travel' === $mode && str_contains($c, '>')) {
                if ('' !== $bt) {
                    $whoLabel = trim($bt.' '.$s->text(6, $r));
                    $who = $this->traveller($d['team'], $bt, $s->text(6, $r));
                    if (null === $who) {
                        $unknown[$whoLabel] = true;
                    }
                }
                if (null !== $who && $day) {
                    $legs[] = $this->leg($d['team'][$who]['key'], $direction, $day, $c, $s->text(4, $r), $s->text(5, $r), $s->text(7, $r));
                }
                continue;
            }
            if ('' !== $bt && '' === $c && self::isHeading($bt) && !('lodging' === $mode && self::isLodgingTitle($bt))) {
                $closeBlock();
                $n = Sheet::norm($bt);
                if (str_starts_with($n, 'planning')) {
                    $mode = 'planning';
                    $planningSeen = true;
                    $day = null;
                } elseif (self::startsWithAny($n, self::LODGING_WORDS)) {
                    $mode = 'lodging';
                    $block = ['type' => 'lodging', 'title' => $bt, 'intro' => [], 'items' => []];
                } else {
                    $mode = 'section';
                    $block = ['type' => 'section', 'title' => $bt, 'lines' => []];
                }
                continue;
            }
            if ('planning' === $mode) {
                if ($day) {
                    $schedule[] = '' !== $c ? ['day' => $day, 'time' => mb_substr($bt, 0, 20), 'label' => mb_substr($c, 0, 255)]
                        : ['day' => $day, 'time' => '', 'label' => mb_substr($bt, 0, 255)];
                }
                continue;
            }
            if ('lodging' === $mode) {
                $this->lodgingLine($block, $bt, $d['team']);
                continue;
            }
            if ('section' === $mode && '' !== $bt) {
                $block['lines'][] = $bt;
            }
        }
        $closeBlock();

        $d['roadmap']['found'] = (bool) ($legs || $schedule || $sections || $lodgings);
        $d['roadmap']['dates'] = array_map(fn ($x) => $x->format('Y-m-d'), $dates);
        $this->roadmapHeader($headerLines, $d);
        $d['legs'] = $legs;
        $d['lodgings'] = $lodgings;
        $d['sections'] = $sections;
        $d['schedule'] = $schedule;
        $d['roadmap']['unknown'] = array_keys($unknown);
    }

    /** En-tête : titre, « Ville du … », « Lieu | adresse | téléphone », « Accueil Cie : … ». */
    private function roadmapHeader(array $lines, array &$d): void
    {
        $e = &$d['event'];
        $d['roadmap']['header'] = implode(' · ', array_slice($lines, 0, 3));
        foreach ($lines as $line) {
            if (str_contains($line, '|')) {
                $parts = array_values(array_filter(array_map('trim', explode('|', $line)), 'strlen'));
                $d['roadmap']['venue'] = $parts[0] ?? '';
                $d['roadmap']['address'] = $parts[1] ?? '';
                $d['roadmap']['phone'] = $parts[2] ?? '';
                $d['roadmap']['city'] = self::cityFromAddress($parts[1] ?? '');
            } elseif (preg_match('/^accueil\b/iu', $line)) {
                $d['roadmap']['contact'] = trim(preg_replace('/^accueil[^:]*:\s*/iu', '', $line));
            }
        }
        unset($e);
    }

    private function traveller(array $team, string $first, string $last): ?int
    {
        $first = Sheet::norm($first);
        $last = Sheet::norm($last);
        $hits = fn (callable $f) => array_keys(array_filter($team, $f));
        foreach ([
            fn ($p) => '' !== $last && Sheet::norm($p['lastName']) === $last,
            fn ($p) => in_array($first, self::words($p['firstName']), true) || Sheet::norm($p['firstName']) === $first,
            fn ($p) => Sheet::norm($p['lastName']) === $first,
            fn ($p) => self::initials($p['firstName']) === $first,
        ] as $rule) {
            $found = $hits($rule);
            if (1 === count($found)) {
                return $found[0];
            }
        }

        return null;
    }

    private function leg(string $key, string $direction, string $day, string $route, string $times, string $ref, string $mode): array
    {
        [$from, $to] = array_map('trim', explode('>', $route, 2)) + [1 => ''];
        $note = [];
        $dep = $arr = '';
        if (preg_match('/(\d{1,2}h\d{0,2})\s*[-–]\s*(\d{1,2}h\d{0,2})/u', $times, $m)) {
            [, $dep, $arr] = $m;
        } elseif ('' !== $times) {
            $note[] = $times;
        }
        $nm = Sheet::norm($mode);
        $appMode = match (true) {
            str_contains($nm, 'vehicule') || str_contains($nm, 'voiture') => 'Véhicule perso',
            str_starts_with($nm, 'avec ') => 'Véhicule perso',
            str_contains($nm, 'ouigo') => 'OUIGO',
            str_contains($nm, 'tgv') => 'TGV INOUI',
            str_contains($nm, 'ter') => 'TER',
            str_contains($nm, 'intercit') => 'Intercités',
            (bool) preg_match('/\bice\b/', $nm) => 'ICE',
            str_contains($nm, 'eurostar') => 'Eurostar',
            str_contains($nm, 'avion') || str_contains($nm, 'vol') => 'Avion',
            str_contains($nm, 'bus') || str_contains($nm, 'commun') => 'Bus',
            str_contains($nm, 'taxi') => 'Taxi',
            default => 'Train',
        };
        if (str_starts_with($nm, 'avec ') || str_starts_with($nm, 'cf ')) {
            $note[] = $mode;
        }
        $nr = Sheet::norm($ref);
        if (str_starts_with($nr, 'cf') || str_contains($nr, ' ')) {
            $note[] = $ref;
            $ref = '';
        }

        return [
            'person' => $key, 'direction' => $direction, 'day' => $day, 'origin' => mb_substr($from, 0, 120), 'destination' => mb_substr($to, 0, 120),
            'depTime' => $dep, 'arrTime' => $arr, 'mode' => $appMode, 'reference' => mb_substr($ref, 0, 60),
            'note' => mb_substr(implode(' · ', $note), 0, 255), 'price' => 0.0,
        ];
    }

    private function lodgingLine(array &$block, string $text, array $team): void
    {
        $items = &$block['items'];
        $first = strtok($text, "\n");
        $titleLike = self::isLodgingTitle($first) || (!$items && self::isHeading($block['title']) && mb_strlen($first) < 70
            && !preg_match('/[.:]\s*$/u', $first) && !str_contains(Sheet::norm($first), 'adresse'));
        if ($titleLike && (!$items || self::isLodgingTitle($first))) {
            $parts = preg_split('/\s+[-–]\s+/u', $first, 2);
            $names = $parts[1] ?? '';
            $people = [];
            foreach (preg_split('/\s*(?:,|\/|\bet\b)\s*/u', $names) as $n) {
                if ('' !== trim($n) && null !== ($i = $this->traveller($team, $n, ''))) {
                    $people[] = $team[$i]['key'];
                }
            }
            $items[] = ['name' => mb_substr(self::title(trim($parts[0], " *\t")), 0, 255) ?: 'Hébergement', 'address' => '', 'phone' => '', 'details' => '', 'people' => $people];
            $text = trim(mb_substr($text, mb_strlen($first)));
            if ('' === $text) {
                return;
            }
        }
        if (!$items) {
            $block['intro'][] = $text;

            return;
        }
        $l = &$items[count($items) - 1];
        $details = [];
        foreach (array_filter(array_map('trim', explode("\n", $text)), 'strlen') as $line) {
            if (preg_match('/^(?:t[ée]l[ée]?phone|t[ée]l)\s*:?\s*(.+)$/iu', $line, $m)) {
                $l['phone'] = mb_substr(trim($m[1]), 0, 60);
            } elseif (preg_match('/^adresse\s*:\s*(.+)$/iu', $line, $m)) {
                $l['address'] = mb_substr(trim($m[1]), 0, 255);
            } elseif ('' === $l['address'] && mb_strlen($line) < 120 && preg_match('/\b\d{4,5}\s+\p{L}/u', $line)) {
                $l['address'] = mb_substr($line, 0, 255);
            } else {
                $details[] = $line;
            }
        }
        if ($details) {
            $l['details'] = trim($l['details']."\n".implode("\n", $details));
        }
    }

    // ───────────────────────── Contrôles ─────────────────────────

    private function finish(array &$d): void
    {
        $e = &$d['event'];
        $rm = &$d['roadmap'];
        $fmt = fn (?\DateTimeImmutable $x) => $x?->format('Y-m-d');
        $gridDays = $this->gridDays($d['team']);

        // Dates de représentation : devis, sinon planning, sinon grilles.
        $perfDays = array_values(array_unique(array_map(fn ($i) => $i['day'],
            array_filter($d['schedule'], fn ($i) => str_contains(Sheet::norm($i['label']), 'representation')))));
        sort($perfDays);
        if (!$e['firstDate'] && $perfDays) {
            $e['firstDate'] = new \DateTimeImmutable($perfDays[0]);
            $e['lastDate'] = new \DateTimeImmutable(end($perfDays));
            $d['warnings'][] = self::warn('info', 'dates', 'Dates du devis illisibles : reprises du planning.');
        } elseif (!$e['firstDate'] && $gridDays) {
            $e['firstDate'] = $gridDays[0];
            $e['lastDate'] = end($gridDays);
            $d['warnings'][] = self::warn('warning', 'dates', 'Dates du devis illisibles : à vérifier.');
        }
        if (!$e['firstDate']) {
            throw new \InvalidArgumentException('Impossible de trouver les dates des représentations.');
        }

        // Feuille de route d'une autre date (copie non mise à jour) ?
        if ($rm['found']) {
            $near = array_filter($rm['dates'], fn ($x) => abs((new \DateTimeImmutable($x))->getTimestamp() - $e['firstDate']->getTimestamp()) < 21 * 86400);
            if (!$near) {
                $rm['mismatch'] = true;
                $d['warnings'][] = self::warn('danger', 'roadmap', sprintf(
                    'La feuille de route concerne une autre date (%s, %s) : trajets, hébergements et planning ne sont pas repris par défaut.',
                    $rm['header'] ?: 'en-tête vide', self::frDate($rm['dates'][0] ?? null)));
            }
        }
        if (!$rm['mismatch']) {
            foreach ($rm['unknown'] ?? [] as $name) {
                $d['warnings'][] = self::warn('warning', 'traveller', "Trajets de « $name » ignorés : personne absente de la distribution du devis.");
            }
        }
        $perfCount = count(array_filter($d['schedule'], fn ($i) => preg_match('/repr[ée]sentation/iu', $i['label'])));
        if (!$rm['mismatch'] && $perfCount) {
            $e['performances'] = $perfCount;
        } elseif ($e['performanceDays'] ?? 0) {
            $e['performances'] = max($e['performances'], $e['performanceDays']);
        }
        unset($e['performanceDays']);

        // Lieu, ville, adresse, contact.
        $venueOk = !$rm['mismatch'] && !empty($rm['venue']);
        $rmCity = $rm['city'] ?? '';
        if ($venueOk && '' !== $e['city'] && '' !== $rmCity && !self::sameCity($e['city'], $rmCity)) {
            $venueOk = false;
            $d['warnings'][] = self::warn('warning', 'venue', sprintf('L’adresse de la feuille de route (%s) ne correspond pas à %s : non reprise.', $rm['address'], $e['city']));
        }
        if ($venueOk) {
            $e['address'] = mb_substr($rm['address'], 0, 255);
            '' === $e['city'] && $e['city'] = $rmCity;
            $raw = $rm['devisVenue'] ?? '';
            if ('' === $e['venue'] || (self::isShouting($raw) && Sheet::norm($raw) === Sheet::norm($rm['venue']))) {
                $e['venue'] = $rm['venue'];
            }
            $e['venueContact'] = trim(($rm['contact'] ?? '').(!empty($rm['phone']) ? "\nStandard : ".$rm['phone'] : ''));
        }
        if ('' === $e['city'] && $d['fileCity']) {
            $e['city'] = $d['fileCity'];
        }
        $e['venue'] = mb_substr($e['venue'] ?: $e['city'] ?: 'Lieu à préciser', 0, 255);
        $e['city'] = mb_substr($e['city'], 0, 120);

        // Le nom du fichier annonce-t-il une autre ville que le contenu ? (« Devis_Spectacle_Saint_Germain_En_Laye »)
        if ($d['fileCity']) {
            $places = array_merge([$e['venue'], $e['city'], $e['address'], $rm['header']], array_column($d['legs'], 'origin'), array_column($d['legs'], 'destination'));
            $fold = fn (string $x) => str_replace(['-', 'ue'], [' ', 'u'], Sheet::norm($x)); // Saarbrücken / Saarbruecken
            $haystack = $fold(implode(' ', $places));
            $words = explode('-', $d['fileCity']);
            $found = false;
            for ($i = 0; $i < count($words) && !$found; ++$i) {
                $found = str_contains($haystack, $fold(implode(' ', array_slice($words, $i))));
            }
            if (!$found) {
                $d['warnings'][] = self::warn('warning', 'filename', sprintf(
                    'Le fichier s’appelle « %s » mais son contenu concerne %s (%s) : sans doute une copie pas encore remplie.',
                    $d['fileCity'], $e['city'] ?: $e['venue'], self::frDate($fmt($e['firstDate']))));
            }
        }

        // Arrivées / départ : cohérents avec les représentations, sinon recalculés depuis les grilles.
        $far = fn (?\DateTimeImmutable $x) => $x && abs($x->getTimestamp() - $e['firstDate']->getTimestamp()) > 15 * 86400;
        if ($far($e['techArrival']) || $far($e['artisticArrival']) || $far($e['departure'])) {
            [$e['techArrival'], $e['artisticArrival'], $e['departure']] = $this->arrivalsFromGrid($d['team'], $e['firstDate'], $e['lastDate']);
            $d['warnings'][] = self::warn('warning', 'arrivals', sprintf(
                'Dates d’arrivée/départ du devis éloignées des représentations : recalculées d’après le rooming (%s → %s).',
                self::frDate($fmt($e['techArrival'])), self::frDate($fmt($e['departure']))));
        }
        $e['departure'] ??= $e['lastDate'];
        // Le rooming fait foi : une présence avant l'arrivée ou après le départ élargit la période de la date.
        [$gTech, , $gDep] = $this->arrivalsFromGrid($d['team'], $e['firstDate'], $e['lastDate']);
        $earliest = min(array_filter([$e['techArrival'], $e['artisticArrival']]) ?: [$e['firstDate']]);
        if ($gTech < $earliest || $gDep > $e['departure']) {
            $e['techArrival'] = $gTech < $earliest ? $gTech : $e['techArrival'];
            $e['departure'] = max($gDep, $e['departure']);
            $d['warnings'][] = self::warn('info', 'period', sprintf('Période élargie aux présences du rooming : %s → %s.',
                self::frDate($fmt($e['techArrival'] ?? $earliest)), self::frDate($fmt($e['departure']))));
        }

        // Prix des billets (onglet DEVIS) : portés par le premier trajet de chaque sens de la feuille de route,
        // ou par un trajet « à compléter » si la feuille de route n'est pas reprise.
        $d['fallbackLegs'] = [];
        foreach ($d['team'] as $p) {
            foreach (['outbound' => $p['outboundPrice'], 'return' => $p['returnPrice']] as $dir => $price) {
                if ($price <= 0) {
                    continue;
                }
                $idx = array_key_first(array_filter($d['legs'], fn ($l) => $l['person'] === $p['key'] && $l['direction'] === $dir));
                if (null !== $idx) {
                    $d['legs'][$idx]['price'] = round($price, 2);
                }
                $d['fallbackLegs'][] = $this->placeholderLeg($p, $dir, round($price, 2), $e);
            }
        }

        foreach (['firstDate', 'lastDate', 'techArrival', 'artisticArrival', 'departure'] as $k) {
            $e[$k] = $fmt($e[$k]);
        }
        foreach ($d['team'] as &$p) {
            ksort($p['days']);
        }
    }

    /** Trajet sans horaires portant le prix du devis (feuille de route absente ou écartée). */
    private function placeholderLeg(array $p, string $dir, float $price, array $e): array
    {
        $days = array_keys(array_filter($p['days'], fn ($x) => $x['night'] || $x['meals']));
        $day = 'outbound' === $dir ? ($days[0] ?? $e['techArrival']?->format('Y-m-d') ?? $e['firstDate']->format('Y-m-d'))
            : ($e['departure'] ?? $e['lastDate'])->format('Y-m-d');
        $home = $p['departureCity'] ?: 'Domicile';
        $city = $e['city'] ?: $e['venue'];

        return [
            'person' => $p['key'], 'direction' => $dir, 'day' => $day,
            'origin' => 'outbound' === $dir ? $home : $city, 'destination' => 'outbound' === $dir ? $city : $home,
            'depTime' => '', 'arrTime' => '', 'mode' => 'Train', 'reference' => '', 'price' => $price,
            'note' => 'Montant repris du devis — horaires à compléter',
        ];
    }

    private function arrivalsFromGrid(array $team, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        $tech = $art = $dep = null;
        foreach ($team as $p) {
            $active = array_filter($p['days'], fn ($x) => $x['night'] || $x['meals']);
            if (!$active) {
                continue;
            }
            $start = new \DateTimeImmutable(array_key_first($active));
            $nights = array_keys(array_filter($p['days'], fn ($x) => $x['night']));
            $end = max(new \DateTimeImmutable(array_key_last($active)), $nights ? (new \DateTimeImmutable(end($nights)))->modify('+1 day') : $start);
            $isTech = (bool) preg_match('/r[ée]gi|tech|lumi|son|vid[ée]o|plateau|admin|prod/iu', $p['role']);
            $isTech ? $tech = min($tech ?? $start, $start) : $art = min($art ?? $start, $start);
            $dep = max($dep ?? $end, $end);
        }

        return [$tech ?? $art ?? $first, $art ?? $tech ?? $first, $dep ?? $last];
    }

    // ───────────────────────── Utilitaires ─────────────────────────

    private function sheet(Spreadsheet $wb, string $name): ?Sheet
    {
        foreach ($wb->getWorksheetIterator() as $ws) {
            if (str_starts_with(Sheet::norm($ws->getTitle()), $name)) {
                return new Sheet($ws);
            }
        }

        return null;
    }

    private function member(array $team, string $name): ?int
    {
        [$last, $first] = self::splitName($name);
        $l = Sheet::norm($last);
        $f = Sheet::norm($first);
        foreach ($team as $i => $p) {
            if (Sheet::norm($p['lastName']) === $l && Sheet::norm($p['firstName']) === $f) {
                return $i;
            }
        }
        foreach ($team as $i => $p) { // « BUECHE Aline » ↔ « BUECHE LOPES Aline »
            $pl = Sheet::norm($p['lastName']);
            if (Sheet::norm($p['firstName']) === $f && '' !== $l && (str_starts_with($pl, $l) || str_starts_with($l, $pl))) {
                return $i;
            }
        }

        return null;
    }

    private function gridDays(array $team): array
    {
        $days = [];
        foreach ($team as $p) {
            foreach ($p['days'] as $day => $x) {
                if ($x['night'] || $x['meals'] || $x['breakfast']) {
                    $days[$day] = true;
                }
            }
        }
        ksort($days);

        return array_map(fn ($x) => new \DateTimeImmutable($x), array_keys($days));
    }

    /** « LE GOFF Romain » → [LE GOFF, Romain] : les mots en capitales forment le nom. */
    public static function splitName(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name));
        $last = [];
        while ($words && mb_strlen($words[0]) > 1 && mb_strtoupper($words[0]) === $words[0] && preg_match('/\p{L}/u', $words[0])) {
            $last[] = array_shift($words);
        }
        if (!$words) { // tout en capitales : dernier mot = prénom
            $words = [array_pop($last)];
        }
        if (!$last) {
            $last = [array_pop($words)];
            [$words, $last] = [$words ?: $last, $words ? $last : []];
        }

        return [implode(' ', $last), implode(' ', $words)];
    }

    /**
     * « 19 au 23 mai 2026 », « 11 et 12 juin 2026 », « 10, 11, 12 juillet 2026 », « du 30 avril au 2 mai 2026 »…
     * Renvoie des [day, month, year|null].
     */
    public static function frenchDates(string $text): array
    {
        $out = $pending = [];
        preg_match_all('/(\d{4})|(\d{1,2})(?:er)?|(\p{L}+)/u', Sheet::norm($text), $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (!empty($t[1])) {
                foreach ($out as &$x) {
                    $x['year'] ??= (int) $t[1];
                }
                unset($x);
            } elseif (isset($t[2]) && '' !== $t[2]) {
                $pending[] = (int) $t[2];
            } elseif (isset($t[3], self::MONTHS[$t[3]])) {
                foreach ($pending as $day) {
                    $out[] = ['day' => $day, 'month' => self::MONTHS[$t[3]], 'year' => null];
                }
                $pending = [];
            }
        }

        return array_values(array_filter($out, fn ($x) => $x['day'] >= 1 && $x['day'] <= 31));
    }

    public static function cityFromFilename(string $filename): string
    {
        $base = preg_replace('/\.xlsx?$/i', '', basename($filename));
        $parts = preg_split('/_/', $base);
        if (count($parts) < 3 || 'devis' !== Sheet::norm($parts[0])) {
            return '';
        }

        return implode('-', array_map(fn ($w) => mb_convert_case($w, MB_CASE_TITLE), array_slice($parts, 2)));
    }

    private static function cityFromAddress(string $address): string
    {
        if (preg_match('/\b(?:\d{5}|\d{2}-\d{3}|\d{4})\s+([\p{L}][\p{L}\' -]*?)(?:,|$)/u', $address, $m)) {
            return self::title(trim($m[1]));
        }

        return '';
    }

    private static function sameCity(string $a, string $b): bool
    {
        $a = Sheet::norm(str_replace('-', ' ', $a));
        $b = Sheet::norm(str_replace('-', ' ', $b));

        return str_contains($a, $b) || str_contains($b, $a);
    }

    private static function isHeading(string $t): bool
    {
        return !str_contains($t, "\n") && mb_strlen($t) <= 70 && preg_match('/\p{Lu}{3}/u', $t) && mb_strtoupper($t) === $t;
    }

    private static function isLodgingTitle(string $t): bool
    {
        $n = Sheet::norm($t);

        return self::startsWithAny($n, ['hotel ', 'appartement ', 'residence ', 'gite ', 'maison ', 'auberge ', 'studio ']) && !str_contains($t, "\n");
    }

    private static function startsWithAny(string $s, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($s, $p)) {
                return true;
            }
        }

        return false;
    }

    private static function isShouting(string $s): bool
    {
        return '' !== $s && mb_strtoupper($s) === $s;
    }

    /** Capitales → casse de titre, sinon inchangé. */
    private static function title(string $s): string
    {
        $s = trim($s);
        if (!self::isShouting($s)) {
            return $s;
        }
        $t = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);

        $t = preg_replace_callback('/(?<=\s)(De|Du|Des|La|Le|Les|Et|En|Sur|Au|Aux)(?=\s)/u', fn ($m) => mb_strtolower($m[1]), $t);

        return mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1);
    }

    private static function sectionTitle(string $s): string
    {
        return mb_substr(self::sentence($s), 0, 255);
    }

    /** « LE MURMURE DES SONGES » → « Le murmure des songes » (texte en capitales seulement). */
    private static function sentence(string $s): string
    {
        $s = trim($s);
        if (!self::isShouting($s)) {
            return $s;
        }
        $t = mb_strtolower($s);

        return mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1);
    }

    private static function words(string $s): array
    {
        return preg_split('/[\s-]+/', Sheet::norm($s));
    }

    private static function initials(string $s): string
    {
        return implode('', array_map(fn ($w) => mb_substr($w, 0, 1), self::words($s)));
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    private static function frDate(?string $ymd): string
    {
        return $ymd ? (new \DateTimeImmutable($ymd))->format('d/m/Y') : '?';
    }

    private static function warn(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }
}
