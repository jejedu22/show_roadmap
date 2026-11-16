<?php

namespace App\Service\Import;

/**
 * Lecture d'un devis PDF (modèle Excel de la compagnie : REPRESENTATIONS, DEFRAIEMENTS, DEPLACEMENTS EQUIPE / DECOR, TOTAL devis),
 * pour comparer le devis signé au réalisé : déplacements de l'équipe (€), repas (midi + soir) et nuitées (« hôtel + pdéj. »).
 * Le texte vient de « pdftotext -layout » : chaque ligne du PDF garde ses colonnes.
 */
class SignedQuoteParser
{
    /** Texte du PDF (3 premières pages), ou exception si le fichier n'est pas un PDF lisible. */
    public function text(string $path): string
    {
        $proc = proc_open(['timeout', '15', 'pdftotext', '-layout', '-enc', 'UTF-8', '-l', '3', $path, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Lecture des PDF indisponible sur le serveur (pdftotext).');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($proc) || '' === trim($out)) {
            throw new \InvalidArgumentException('PDF illisible : il doit contenir du texte (un devis exporté depuis Excel, pas une image scannée).');
        }

        return $out;
    }

    /**
     * @return array{number: string, date: ?string, show: string, organizer: string, period: string, performances: ?int,
     *               cession: ?float, allowances: ?float, lunches: ?int, dinners: ?int, meals: ?int, nights: ?int, teamTravel: ?float, teamLines: list<array{label: string, detail: string, amount: float}>,
     *               decor: ?float, travelTotal: ?float, totalHt: ?float, totalTtc: ?float, checks: list<string>, warnings: list<string>}
     */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\R/u', $text)), fn ($l) => '' !== trim($l)));
        $find = function (string $re) use ($lines): ?array {
            foreach ($lines as $i => $l) {
                if (preg_match($re, $l, $m)) {
                    return [$i, $m];
                }
            }

            return null;
        };
        $after = fn (string $label, string $line) => trim(preg_replace('/^\s*'.$label.'\s*/iu', '', $line));
        $d = ['number' => '', 'date' => null, 'show' => '', 'organizer' => '', 'period' => '', 'performances' => null, 'cession' => null, 'allowances' => null, 'lunches' => null, 'dinners' => null,
            'meals' => null, 'nights' => null, 'teamTravel' => null, 'teamLines' => [], 'decor' => null, 'travelTotal' => null, 'totalHt' => null, 'totalTtc' => null,
            'checks' => [], 'warnings' => []];

        // En-tête
        ($m = $find('/N°\s+(\S+)\s*$/u')) && $d['number'] = $m[1][1];
        ($m = $find('/Devis fourni le\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/iu')) && $d['date'] = sprintf('%04d-%02d-%02d', $m[1][3], $m[1][2], $m[1][1]);
        ($m = $find('/Spectacle\s*:\s*(.+)$/u')) && $d['show'] = trim($m[1][1]);
        if ($m = $find('/^\s*ORGANISATEUR\b(.*)$/u')) {
            $d['organizer'] = trim($m[1][1]);
        }
        if ($m = $find('/^\s*PERIODE\b(.*)$/u')) {
            // Période sur la même ligne, ou sur la ligne suivante (cellule fusionnée)
            $d['period'] = trim($m[1][1]) ?: (isset($lines[$m[0] + 1]) && !preg_match('/NOMBRE DE REPRESENTATIONS/u', $lines[$m[0] + 1]) ? trim($lines[$m[0] + 1]) : '');
        }
        ($m = $find('/NOMBRE DE REPRESENTATIONS\s+(\d+)/u')) && $d['performances'] = (int) $m[1][1];
        if ('' === $d['organizer'] && ($m = $find('/^\s*ORGANISATEUR\s*$/u'))) {
            $d['organizer'] = trim($lines[$m[0] + 1] ?? '');
        }

        // Cession (montant des représentations) et total des défraiements, pour information
        ($m = $find('/TOTAL Cession\s+(-?\d[\d\s\x{a0}\x{202f}]*,\d{2})\s*€?\s*$/iu')) && $d['cession'] = self::amount($m[1][1]);
        ($m = $find('/TOTAL D[ée]fraiements\s+(-?\d[\d\s\x{a0}\x{202f}]*,\d{2})\s*€?\s*$/iu')) && $d['allowances'] = self::amount($m[1][1]);

        // Défraiements : colonnes « repas midi », « repas soir », « hôtel + pdéj. », totaux sur la ligne « Totaux »
        $head = $find('/repas midi.*repas soir.*h[ôo]tel/iu');
        $tot = $find('/^(.*?)\bTotaux\b(.*)$/u');
        if ($head && $tot) {
            $cols = [];
            foreach (['lunches' => '/repas midi/iu', 'dinners' => '/repas soir/iu', 'nights' => '/h[ôo]tel/iu'] as $k => $re) {
                preg_match($re, $lines[$head[0]], $mm, PREG_OFFSET_CAPTURE);
                $cols[$k] = mb_strlen(substr($lines[$head[0]], 0, $mm[0][1])) + 5; // milieu approximatif de l'intitulé
            }
            $line = $lines[$tot[0]];
            $start = mb_strpos($line, 'Totaux') + 6;
            preg_match_all('/\d+/u', mb_substr($line, $start), $nums, PREG_OFFSET_CAPTURE);
            foreach ($nums[0] as [$n, $off]) {
                $pos = $start + mb_strlen(substr(mb_substr($line, $start), 0, $off)) + 1;
                $best = null;
                foreach ($cols as $k => $c) {
                    if (null === $best || abs($pos - $c) < abs($pos - $cols[$best])) {
                        $best = $k;
                    }
                }
                null === $d[$best] && $d[$best] = (int) $n;
            }
        } else {
            $d['warnings'][] = 'Tableau des défraiements introuvable : repas et nuitées à saisir à la main.';
        }
        if (null !== $d['lunches'] || null !== $d['dinners']) {
            $d['meals'] = (int) $d['lunches'] + (int) $d['dinners'];
        }
        if ($m = $find('/Total d[ée]fraiements repas\s+(\d+)\s*$/iu')) {
            null !== $d['meals'] && (int) $m[1][1] === $d['meals']
                ? $d['checks'][] = sprintf('Repas : %d midis + %d soirs = %d, comme le « Total défraiements repas » du devis.', $d['lunches'], $d['dinners'], $d['meals'])
                : $d['warnings'][] = sprintf('Repas : le devis indique %d au total, la lecture des colonnes donne %s. Vérifiez.', $m[1][1], $d['meals'] ?? '—');
            $d['meals'] ??= (int) $m[1][1];
        }

        // Déplacements : lignes de la section jusqu'à TOTAL DEPLACEMENTS ; la ligne du décor est à part
        $sec = $find('/DEPLACEMENTS\s+EQUIPE/iu');
        if ($sec) {
            for ($i = $sec[0] + 1; $i < count($lines); ++$i) {
                $l = $lines[$i];
                if (preg_match('/TOTAL DEPLACEMENTS\s+(.+?)\s*€?\s*$/iu', $l, $mm)) {
                    $d['travelTotal'] = self::amount($mm[1]);
                    break;
                }
                if (!preg_match('/^\s*(.+?)\s{2,}(.*?)\s*(-?\d[\d\s\x{a0}\x{202f}]*,\d{2})\s*€?\s*$/u', $l, $mm)) {
                    continue;
                }
                $label = trim($mm[1]);
                $detail = trim($mm[2]);
                if (preg_match('/d[ée]cor/iu', $label)) {
                    $d['decor'] = ($d['decor'] ?? 0) + self::amount($mm[3]);
                } else {
                    $d['teamLines'][] = ['label' => $label, 'detail' => $detail, 'amount' => self::amount($mm[3])];
                }
            }
            if ($d['teamLines']) {
                $d['teamTravel'] = round(array_sum(array_column($d['teamLines'], 'amount')), 2);
            }
            if (null !== $d['travelTotal'] && null !== $d['teamTravel']) {
                abs($d['teamTravel'] + ($d['decor'] ?? 0) - $d['travelTotal']) < 0.01
                    ? $d['checks'][] = sprintf('Déplacements : équipe %s € + décor %s € = %s €, comme le « TOTAL DEPLACEMENTS » du devis.', self::fr($d['teamTravel']), self::fr($d['decor'] ?? 0), self::fr($d['travelTotal']))
                    : $d['warnings'][] = sprintf('Déplacements : équipe + décor ne font pas le « TOTAL DEPLACEMENTS » (%s €). Vérifiez le montant de l\'équipe.', self::fr($d['travelTotal']));
            }
        }
        null === $d['teamTravel'] && $d['warnings'][] = 'Montant des déplacements de l\'équipe introuvable : à saisir à la main.';
        null === $d['nights'] && $d['warnings'][] = 'Nombre de nuitées (« hôtel + pdéj. ») introuvable : à saisir à la main.';

        // Total du devis (pour information) : le bloc « TOTAL devis », pas « TOTAL avenant »
        if ($sec = $find('/^\s*TOTAL devis\s*$/iu')) {
            foreach (array_slice($lines, $sec[0] + 1, 4) as $l) {
                preg_match('/TOTAL HT\s+(.+?)\s*€?\s*$/u', $l, $mm) && $d['totalHt'] = self::amount($mm[1]);
                preg_match('/TOTAL TTC\s+(.+?)\s*€?\s*$/u', $l, $mm) && $d['totalTtc'] = self::amount($mm[1]);
            }
        }
        if ('' === $d['number'] && null === $d['teamTravel'] && null === $d['meals']) {
            throw new \InvalidArgumentException('Ce PDF ne ressemble pas à un devis de la compagnie (numéro, défraiements et déplacements introuvables).');
        }

        return $d;
    }

    /** « 1 100,00 » → 1100.0 */
    private static function amount(string $s): float
    {
        return (float) str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim($s));
    }

    private static function fr(float $x): string
    {
        return number_format($x, 2, ',', ' ');
    }
}
