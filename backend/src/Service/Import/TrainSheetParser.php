<?php

namespace App\Service\Import;

use App\Entity\Event;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Lit l'onglet « Horaires TGV » des classeurs de devis : trajets aller (bloc de gauche) et retour (bloc de droite).
 *
 * Chaque côté a 7 colonnes : prénom | départ | arrivée | mode (ou date) | référence | prix | réservé le. Un trajet tient
 * en deux lignes (gares, puis horaires et billet), mais les classeurs varient : prénom sur l'une ou l'autre ligne,
 * date propre à un trajet dans la colonne « mode » de la ligne des gares, nouvelles dates en cours d'onglet,
 * « Autonome » (avec ou sans précision), trajets en voiture sur une seule ligne, lignes « Échange » / « Annulation »
 * qui corrigent le prix du trajet précédent, totaux en bas d'onglet. Les lignes sont donc lues par blocs (séparés par
 * des lignes vides) et interprétées d'après leur contenu, pas leur position.
 */
class TrainSheetParser
{
    private const ADJUSTMENTS = ['echange', 'annulation', 'remboursement', 'avoir'];

    /** @return array{persons: list<array>, warnings: list<string>} */
    public function parse(string $path, Event $event): array
    {
        $reader = new Xlsx();
        $reader->setReadEmptyCells(false);
        $wb = $reader->load($path);
        $ws = null;
        foreach ($wb->getWorksheetIterator() as $sheet) {
            $n = Sheet::norm($sheet->getTitle());
            if (str_starts_with($n, 'horaires') || str_contains($n, 'tgv') || str_contains($n, 'train')) {
                $ws = $sheet;
                break;
            }
        }
        if (!$ws) {
            throw new \InvalidArgumentException('Onglet « Horaires TGV » introuvable dans ce classeur.');
        }
        $s = new Sheet($ws);
        [$headerRow, $left, $right] = $this->layout($s);
        $warnings = [];
        $persons = [];
        foreach ([['outbound', $left], ['return', $right]] as [$direction, $col]) {
            if (null === $col) {
                continue;
            }
            $default = $s->date($col, $headerRow)
                ?? ('outbound' === $direction ? ($event->techArrival ?? $event->artisticArrival ?? $event->firstDate) : ($event->departure ?? $event->lastDate));
            foreach ($this->side($s, $col, $headerRow, $default, $direction, $warnings) as $name => $legs) {
                $persons[$name] = [...($persons[$name] ?? []), ...$legs];
            }
        }
        $wb->disconnectWorksheets();

        $out = [];
        foreach ($persons as $name => $legs) {
            $out[] = ['name' => $name, 'legs' => self::dedupe($legs)];
        }
        $this->checkDates($out, $event, $warnings);

        return ['persons' => $out, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Ligne d'en-tête et première colonne de chaque côté : les deux dates de départ en haut de l'onglet
     * (B et J d'ordinaire). À défaut, B et J.
     */
    private function layout(Sheet $s): array
    {
        for ($r = 1; $r <= 6; ++$r) {
            $dates = [];
            for ($c = 1; $c <= 20; ++$c) {
                if ($s->date($c, $r)) {
                    $dates[] = $c;
                }
            }
            if (count($dates) >= 2 && $dates[1] - $dates[0] >= 7) {
                return [$r, $dates[0], $dates[1]];
            }
            if (1 === count($dates)) {
                return [$r, $dates[0], $dates[0] + 8];
            }
        }

        return [1, 2, 10];
    }

    /**
     * Trajets d'un côté, par prénom.
     *
     * @return array<string, list<array>>
     */
    private function side(Sheet $s, int $c, int $headerRow, \DateTimeImmutable $default, string $direction, array &$warnings): array
    {
        $byName = [];
        $section = $default;     // date courante (en-tête, puis lignes « date seule » en cours d'onglet)
        $block = null;           // bloc de lignes non vides : ['name', 'day', 'legs', 'pending']
        $lastName = null;

        $close = function () use (&$block, &$byName, &$lastName, &$warnings, $direction) {
            if (!$block) {
                return;
            }
            if ($block['pending']) {
                $block['legs'][] = $this->leg($block['pending'], $direction, ['note' => 'Horaires à compléter']);
            }
            $legs = self::replaced(array_values(array_filter($block['legs'])));
            $name = $block['name'];
            if (!$name && $legs && $lastName) { // ligne isolée sous un bloc (trajet en voiture séparé par des lignes vides)
                $name = $lastName;
                $warnings[] = sprintf('%s : trajet sans prénom (%s) rattaché à %s, à vérifier.', 'outbound' === $direction ? 'Aller' : 'Retour',
                    trim($legs[0]['origin'].' → '.$legs[0]['destination'], ' →') ?: $legs[0]['mode'], $name);
            }
            if ($name) {
                $byName[$name] = [...($byName[$name] ?? []), ...$legs];
                $lastName = $name;
            }
            $block = null;
        };

        for ($r = $headerRow + 1, $max = $s->maxRow(); $r <= $max; ++$r) {
            $cell = fn (int $offset) => $s->value($c + $offset, $r);
            $text = fn (int $offset) => $s->text($c + $offset, $r);
            $nameCell = $cell(0);
            $from = $text(1);
            $to = $text(2);
            $modeCell = $cell(3);
            $ref = $text(4);
            $price = $s->number($c + 5, $r);
            $booked = $s->date($c + 6, $r);
            $empty = null === $nameCell && '' === $from && '' === $to && (null === $modeCell || '' === trim((string) $this->str($modeCell)))
                && '' === $ref && null === $price && null === $booked;
            if ($empty) {
                $close();
                continue;
            }
            // Ligne « date seule » : nouvelle date pour les blocs suivants
            if ($nameCell instanceof \DateTimeImmutable && '' === $from && '' === $to) {
                $close();
                $section = $nameCell;
                continue;
            }
            $block ??= ['name' => null, 'day' => $section, 'legs' => [], 'pending' => null];
            $name = trim($this->str($nameCell));
            if ('' !== $name && !$block['name'] && !preg_match('/^[\d\s.,€-]+$/u', $name)) {
                $block['name'] = $name;
            }

            // Échange, annulation, avoir : corrige le prix du trajet précédent (même sur une ligne de gares)
            $isTime = self::isTime($from);
            $adjusts = in_array(Sheet::norm($ref), self::ADJUSTMENTS, true)
                || (null !== $price && '' === $from && '' === $to && !$this->hasMode($modeCell) && '' === $ref);
            $keyword = in_array(Sheet::norm($ref), self::ADJUSTMENTS, true);
            if ($adjusts && null !== $price && !$keyword && $block['pending'] && '' === $from) {
                $block['pending']['price'] = ($block['pending']['price'] ?? 0) + $price; // prix posé sur la ligne du prénom
                $block['pending']['bookedOn'] ??= $booked;
                continue;
            }
            if ($adjusts && null !== $price) {
                $last = array_key_last($block['legs']);
                if (null !== $last) {
                    $this->adjust($block['legs'][$last], $price, $ref ?: 'Ajustement', $booked);
                    $price = null;
                } elseif ('' === $from && '' === $to) {
                    continue; // total ou montant isolé, sans trajet auquel le rattacher
                }
            }

            if ($isTime && $block['pending']) {
                // Ligne d'horaires : complète les gares de la ligne précédente
                [$dep, $arr] = [$from, self::isTime($to) ? $to : ''];
                $block['legs'][] = $this->leg($block['pending'], $direction, [
                    'depTime' => self::time($dep), 'arrTime' => self::time($arr), 'mode' => $this->str($modeCell),
                    'reference' => $adjusts ? '' : $ref, 'price' => $price, 'bookedOn' => $booked,
                ]);
                $block['pending'] = null;
            } elseif (str_starts_with(Sheet::norm($from), 'autonome')) {
                // « Autonome », « Autonome - véhicule perso », « Autonome - Avec Cathy »
                $detail = trim(preg_replace('/^autonome\s*[-–:]?\s*/iu', '', $from));
                $mode = $this->str($modeCell) ?: $detail;
                $base = $block['pending'] ?? ['origin' => '', 'destination' => '', 'day' => $block['day']];
                // La précision (« Avec Cathy ») sert de mode quand la colonne mode est vide : elle revient alors en note via mode()
                $block['legs'][] = $this->leg($base, $direction, [
                    'mode' => '' !== $mode ? $mode : 'Véhicule perso', 'price' => $price, 'bookedOn' => $booked, 'reference' => $adjusts ? '' : $ref,
                    'note' => trim('Autonome'.($detail && !self::isCar($detail) && '' !== $this->str($modeCell) ? ' – '.$detail : '')),
                ]);
                $block['pending'] = null;
            } elseif ('' !== $from && '' !== $to && !$isTime) {
                // Ligne de gares : éventuelle date propre au trajet (colonne « mode »), ou trajet complet sur une ligne
                if ($block['pending']) {
                    $block['legs'][] = $this->leg($block['pending'], $direction, ['note' => 'Horaires à compléter']);
                }
                if ($modeCell instanceof \DateTimeImmutable) {
                    $block['day'] = $modeCell;
                }
                $block['pending'] = ['origin' => $from, 'destination' => $to, 'day' => $block['day']];
                if (!$adjusts && (null !== $price || ('' !== $ref && !$this->hasMode($modeCell)))) {
                    [$block['pending']['price'], $block['pending']['reference'], $block['pending']['bookedOn']] = [$price, $ref, $booked];
                    $price = null;
                }
                if ($this->hasMode($modeCell) && !$this->nextIsTimes($s, $c, $r)) {
                    $block['legs'][] = $this->leg($block['pending'], $direction, [
                        'mode' => $this->str($modeCell), 'price' => $price, 'bookedOn' => $booked, 'note' => $adjusts ? '' : $ref,
                    ]);
                    $block['pending'] = null;
                }
            } elseif ('' === $from && '' === $to && $this->hasMode($modeCell) && null !== $price) {
                // Frais de déplacement sans gares (« Véhicule perso à rembourser 7,5 »)
                $block['legs'][] = $this->leg(['origin' => '', 'destination' => '', 'day' => $block['day']], $direction, [
                    'mode' => $this->str($modeCell), 'price' => $price, 'bookedOn' => $booked, 'note' => 'Trajet à préciser',
                ]);
            }
        }
        $close();

        return $byName;
    }

    private function leg(array $base, string $direction, array $f): array
    {
        if (isset($base['price']) || isset($base['reference'])) {
            $f['price'] = null === ($f['price'] ?? null) && null === $base['price'] ? null : ($f['price'] ?? 0) + ($base['price'] ?? 0);
            $own = trim((string) ($f['reference'] ?? ''));
            $other = trim((string) ($base['reference'] ?? ''));
            $f['reference'] = $own ?: $other;
            if ($own && $other && $own !== $other) {
                $f['note'] = trim(($f['note'] ?? '').' · Autre billet : '.$other, ' ·');
            }
            $f['bookedOn'] ??= $base['bookedOn'] ?? null;
        }
        $rawMode = trim((string) ($f['mode'] ?? ''));
        [$mode, $modeNote] = self::mode($rawMode, $base);
        $ref = trim(preg_replace('/^\*+|\*+$/', '', trim((string) ($f['reference'] ?? ''))));
        $notes = array_filter([$f['note'] ?? '', $modeNote]);
        if ('' !== $ref && (str_contains(Sheet::norm($ref), ' ') || str_starts_with(Sheet::norm($ref), 'cf'))) {
            $notes[] = $ref; // « Cf billet en pj » : pas une référence
            $ref = '';
        }

        return [
            'direction' => $direction,
            'day' => $base['day']->format('Y-m-d'),
            'origin' => mb_substr($base['origin'], 0, 120),
            'destination' => mb_substr($base['destination'], 0, 120),
            'depTime' => $f['depTime'] ?? '',
            'arrTime' => $f['arrTime'] ?? '',
            'mode' => $mode,
            'reference' => mb_substr($ref, 0, 60),
            'price' => null !== ($f['price'] ?? null) ? round((float) $f['price'], 2) : 0.0,
            'bookedOn' => ($f['bookedOn'] ?? null)?->format('Y-m-d'),
            'note' => mb_substr(implode(' · ', array_unique($notes)), 0, 255),
            'cancelled' => false,
            'replaced' => false,
        ];
    }

    /** Échange ou annulation : le prix du trajet est corrigé ; une annulation qui rembourse tout l'écarte. */
    private function adjust(array &$leg, float $amount, string $label, ?\DateTimeImmutable $on): void
    {
        $before = $leg['price'];
        $leg['price'] = round($before + $amount, 2);
        $label = mb_convert_case(Sheet::norm($label), MB_CASE_TITLE);
        $leg['note'] = trim($leg['note'].' · '.sprintf('%s %s %s €%s', $label, $amount >= 0 ? '+' : '−', number_format(abs($amount), 2, ',', ' '),
            $on ? ' le '.$on->format('d/m/Y') : ''), ' ·');
        if ('Annulation' === $label && $before > 0 && abs($leg['price']) <= 0.005) {
            $leg['cancelled'] = true; // billet entièrement remboursé : trajet abandonné
            $leg['price'] = 0.0;
        } elseif ($leg['price'] < 0) {
            $leg['note'] .= ' · remboursement supérieur au prix de ce trajet : à vérifier';
        }
    }

    /** Mode de l'application d'après le libellé du classeur ; [mode, note éventuelle]. */
    private static function mode(string $raw, array $base): array
    {
        $n = Sheet::norm($raw);
        $plane = (bool) preg_match('/a[eé]roport|airport|orly|cdg|roissy|exup[eé]ry|blagnac|marignane/iu', $base['origin'].' '.$base['destination']);

        return match (true) {
            '' === $n => [$plane ? 'Avion' : 'Train', ''],
            str_contains($n, 'ouigo') => ['OUIGO', ''],
            'direct' === $n || str_contains($n, 'tgv') || str_contains($n, 'inoui') => ['TGV INOUI', ''],
            str_contains($n, 'ter') && !str_contains($n, 'inter') => ['TER', ''],
            str_contains($n, 'intercit') => ['Intercités', ''],
            str_starts_with($n, 'avec ') => ['Véhicule perso', $raw],
            self::isCar($raw) => ['Véhicule perso', str_contains($n, 'rembours') ? 'À rembourser' : ''],
            str_contains($n, 'navette') || str_contains($n, 'bus') || str_contains($n, 'car ') => ['Bus', 'navette' === $n ? 'Navette' : $raw],
            str_contains($n, 'taxi') => ['Taxi', ''],
            str_contains($n, 'avion') || str_contains($n, 'vol ') => ['Avion', ''],
            default => [mb_substr($raw, 0, 60), ''], // ICE, Eurostar, RE1, RB70…
        };
    }

    private static function isCar(string $s): bool
    {
        $n = Sheet::norm($s);

        return str_contains($n, 'vehicule') || str_contains($n, 'voiture');
    }

    private function hasMode(mixed $cell): bool
    {
        return !$cell instanceof \DateTimeImmutable && '' !== trim($this->str($cell)) && null === (is_numeric($cell) ? $cell : null);
    }

    private function nextIsTimes(Sheet $s, int $c, int $r): bool
    {
        $next = $s->text($c + 1, $r + 1);

        return self::isTime($next) || str_starts_with(Sheet::norm($next), 'autonome');
    }

    private function str(mixed $v): string
    {
        return $v instanceof \DateTimeImmutable || null === $v ? '' : trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    private static function isTime(string $s): bool
    {
        return (bool) preg_match('/^\d{1,2}\s*[h:]\s*\d{0,2}$/iu', trim($s));
    }

    /** « 9:05 », « 9 h 05 », « 14h » → « 9h05 », « 14h00 ». */
    private static function time(string $s): string
    {
        if (!preg_match('/^(\d{1,2})\s*[h:]\s*(\d{0,2})$/iu', trim($s), $m)) {
            return '';
        }

        return (int) $m[1].'h'.str_pad($m[2] ?: '00', 2, '0', STR_PAD_LEFT);
    }

    /**
     * Billet échangé : le même parcours (mêmes gares, même jour) saisi deux fois dans un bloc ; le premier a été
     * remplacé par le second. Il reste proposé, mais décoché.
     */
    private static function replaced(array $legs): array
    {
        $key = fn ($l) => implode('|', [$l['day'], Sheet::norm($l['origin']), Sheet::norm($l['destination'])]);
        foreach ($legs as $i => &$l) {
            // Seul un billet échangé ou corrigé (ligne « Échange », « Ajustement »…) peut avoir été remplacé
            if ('' === $l['origin'] || $l['cancelled'] || !preg_match('/€/u', $l['note'])) {
                continue;
            }
            foreach (array_slice($legs, $i + 1) as $later) {
                if (!$later['cancelled'] && $key($later) === $key($l) && $later['depTime'] !== $l['depTime']) {
                    $l['replaced'] = true;
                    $l['note'] = trim($l['note'].' · Remplacé par le trajet de '.($later['depTime'] ?: 'la ligne suivante'), ' ·');
                    break;
                }
            }
        }

        return $legs;
    }

    /**
     * Participant correspondant à un prénom de l'onglet (« Cécile », « JC » pour Jean-Christophe, « Orlov » pour un nom
     * de famille). Seule une correspondance unique est retenue.
     *
     * @param array<int, array{first: string, last: string}> $participants par identifiant de participation
     */
    public static function match(string $name, array $participants): ?int
    {
        $n = Sheet::norm($name);
        $words = fn (string $s) => preg_split('/[\s-]+/', Sheet::norm($s), -1, PREG_SPLIT_NO_EMPTY);
        $initials = fn (string $s) => implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words($s)));
        foreach ([
            fn ($p) => Sheet::norm($p['first']) === $n,
            fn ($p) => Sheet::norm($p['first'].' '.$p['last']) === $n || Sheet::norm($p['last'].' '.$p['first']) === $n,
            fn ($p) => in_array($n, $words($p['first']), true),
            fn ($p) => Sheet::norm($p['last']) === $n || in_array($n, $words($p['last']), true),
            fn ($p) => mb_strlen($n) >= 2 && $initials($p['first']) === $n,
        ] as $rule) {
            $found = array_keys(array_filter($participants, $rule));
            if (1 === count($found)) {
                return $found[0];
            }
        }

        return null;
    }

    /** Mêmes trajets recopiés (classeurs dupliqués) : gardés une fois. */
    private static function dedupe(array $legs): array
    {
        $seen = [];

        return array_values(array_filter($legs, function ($l) use (&$seen) {
            $key = implode('|', [$l['direction'], $l['day'], Sheet::norm($l['origin']), Sheet::norm($l['destination']), $l['depTime'], $l['mode']]);

            return !isset($seen[$key]) && ($seen[$key] = true);
        }));
    }

    /** Onglet d'une autre date (copie d'un classeur) : trajets à plus de 15 jours de la date. */
    private function checkDates(array $persons, Event $event, array &$warnings): void
    {
        $days = array_merge(...array_map(fn ($p) => array_column($p['legs'], 'day'), $persons ?: [['legs' => []]]));
        if (!$days) {
            return;
        }
        $from = $event->start()->modify('-15 days')->format('Y-m-d');
        $to = ($event->departure ?? $event->lastDate)->modify('+15 days')->format('Y-m-d');
        $outside = array_filter($days, fn ($d) => $d < $from || $d > $to);
        if (count($outside) === count($days)) {
            sort($days);
            $warnings[] = sprintf('Les trajets de l\'onglet (du %s au %s) ne correspondent pas aux dates de cette date de tournée : classeur d\'une autre date ?',
                (new \DateTimeImmutable($days[0]))->format('d/m/Y'), (new \DateTimeImmutable(end($days)))->format('d/m/Y'));
        }
    }
}
