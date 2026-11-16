<?php

namespace App\Service\Import;

use App\Entity\Event;
use App\Service\Lists;

/**
 * Lecture du planning d'une fiche technique PDF (document Word, planning en dernière page) : jours (« Jeudi 19 nov 26 »),
 * créneaux (« 14h / 18h », « 20h », « 21h45 / Minuit »), activité, effectifs P / L / S. Les remarques (colonne de droite)
 * et les effectifs ne sont pas repris dans le planning ; l'intitulé est rapproché de la liste « Intitulés de planning »,
 * le texte de la fiche devient la précision. Texte issu de « pdftotext -layout » (colonnes conservées).
 */
class TechPlanningParser
{
    private const DAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];
    private const MONTHS = ['janv' => 1, 'jan' => 1, 'fevr' => 2, 'fev' => 2, 'mars' => 3, 'mar' => 3, 'avri' => 4, 'avr' => 4, 'mai' => 5, 'juin' => 6,
        'juil' => 7, 'aout' => 8, 'sept' => 9, 'sep' => 9, 'octo' => 10, 'oct' => 10, 'nove' => 11, 'nov' => 11, 'dece' => 12, 'dec' => 12];
    private const TIME = '(?:\d{1,2}\s?h\s?\d{0,2}|minuit|midi)';
    private const WEEKDAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const MONTH_NAMES = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * Intitulés proposés selon les mots de l'activité, dans l'ordre (le premier présent dans la liste de l'instance l'emporte).
     * Clé : expression cherchée dans le texte sans accents ni casse.
     */
    private const RULES = [
        'representation' => ['Représentation'], 'demontage' => ['Démontage'], 'dechargement' => ['Déchargement'], 'chargement' => ['Chargement'],
        'inter[ -]?service' => ['Inter-service son'], 'plateau (pour les )?danseurs' => ['Plateau pour les danseurs'], 'echauffement' => ['Échauffement danseurs'],
        'repetition' => ['Répétition'], 'filage' => ['Filage'], 'generale' => ['Générale'], 'raccord' => ['Raccords'],
        'conduite.*lumiere|lumiere.*conduite' => ['Conduite lumière', 'Conduite'], 'conduite' => ['Conduite'],
        'balance' => ['Balance son'], 'reglage son' => ['Réglage son'], 'reglage' => ['Réglage lumière'],
        'nettoyage.*mise' => ['Nettoyage plateau + mise', 'Nettoyage plateau'], 'nettoyage' => ['Nettoyage plateau'],
        'montage plateau' => ['Montage plateau', 'Montage'], 'montage' => ['Montage'], 'mise en loge' => ['Mise en loge'],
        'pause repas|repas' => ['Pause repas'], 'pause' => ['Pause'], 'atelier' => ['Atelier'], 'rencontre' => ['Rencontre avec le public'],
        'transfert' => ['Transfert'], 'arrivee' => ['Arrivée'], 'depart' => ['Départ'],
    ];

    public function __construct(private Lists $lists)
    {
    }

    /** Texte du PDF, page par page (\f entre les pages), ou exception si ce n'est pas un PDF avec du texte. */
    public function text(string $path): string
    {
        $proc = proc_open(['timeout', '15', 'pdftotext', '-layout', '-enc', 'UTF-8', '-l', '30', $path, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Lecture des PDF indisponible sur le serveur (pdftotext).');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (0 !== proc_close($proc) || '' === trim($out)) {
            throw new \InvalidArgumentException('PDF illisible : il doit contenir du texte (une fiche exportée depuis Word, pas une image scannée).');
        }

        return $out;
    }

    /**
     * @return array{page: int, pages: int, rows: list<array{day: ?string, dayText: string, time: string, text: string, staff: string,
     *               label: string, detail: string, checked: bool}>, warnings: list<string>}
     */
    public function parse(string $text, Event $ev): array
    {
        // Page du planning : la dernière qui a des jours et des créneaux horaires
        $pages = array_values(array_filter(explode("\f", $text), fn ($p) => '' !== trim($p)));
        $page = null;
        for ($i = count($pages) - 1; $i >= 0; --$i) {
            if (preg_match_all('/^\s{0,6}'.self::TIME.'\b/mui', $pages[$i]) >= 2 && preg_match('/^\s{0,6}(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\b/mui', $pages[$i])) {
                $page = $i;
                break;
            }
        }
        if (null === $page) {
            throw new \InvalidArgumentException('Planning introuvable : aucune page avec des jours (« Jeudi 19 nov ») et des horaires (« 14h / 18h »).');
        }
        $lines = array_map(fn ($l) => mb_str_split(rtrim($l)) ?: [], explode("\n", $pages[$page]));
        $str = fn (array $c, int $from = 0, ?int $len = null) => implode('', array_slice($c, $from, $len));

        // Colonnes des effectifs : ligne d'en-tête « P L S » (ou « P L SV »)
        $staffCols = [];
        $headerAt = -1;
        foreach ($lines as $n => $c) {
            if (preg_match('/(?<!\S)P\s{1,8}L\s{1,8}S\w?(?!\S)/u', $str($c), $m, PREG_OFFSET_CAPTURE)) {
                $start = mb_strlen(substr($str($c), 0, $m[0][1]));
                preg_match_all('/\S+/u', $m[0][0], $tok, PREG_OFFSET_CAPTURE);
                foreach ($tok[0] as [$t, $off]) {
                    $staffCols[] = $start + mb_strlen(substr($m[0][0], 0, $off));
                }
                $headerAt = $n;
                break;
            }
        }
        $staffStart = $staffCols ? $staffCols[0] - 2 : PHP_INT_MAX;

        $rows = $days = [];
        $warnings = [];
        $cur = null;     // ligne de planning en cours (pour les suites d'activité sur plusieurs lignes)
        $descCol = 0;    // colonne de début de l'activité
        $dayIdx = null;  // jour en cours (index dans $days)
        $pending = [];   // lignes avant le premier jour (« En amont du jeudi : pré-montage »)
        foreach ($lines as $n => $c) {
            $line = $str($c);
            if ('' === trim($line)) {
                continue;
            }
            $body = rtrim($str($c, 0, min(count($c), $staffStart)));

            // Jour : « Jeudi 19 nov 26 », « Vendredi 20 nov 2026 », « Mardi 17 »
            if (preg_match('/^\s{0,6}(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+(\d{1,2})(?:er)?\b\.?\s*(.*)$/ui', $body, $m)) {
                $rest = $m[3];
                $month = $year = null;
                if (preg_match('/^([\p{L}]+)\.?\s*(.*)$/u', $rest, $mm) && null !== ($mo = self::month($mm[1]))) {
                    $month = $mo;
                    $rest = $mm[2];
                    if (preg_match('/^(\d{4}|\d{2})\b\s*(.*)$/u', $rest, $my)) {
                        $year = (int) $my[1] < 100 ? 2000 + (int) $my[1] : (int) $my[1];
                        $rest = $my[2];
                    }
                }
                $days[] = ['weekday' => self::DAYS[Sheet::norm($m[1])], 'day' => (int) $m[2], 'month' => $month, 'year' => $year,
                    'text' => trim(preg_replace('/\s+/u', ' ', mb_substr($body, 0, mb_strlen($body) - mb_strlen($rest)))), 'date' => null];
                $dayIdx = count($days) - 1;
                $cur = null;
                if ('' !== trim($rest)) { // activité sur la ligne du jour, sans horaire
                    $descCol = mb_strlen(rtrim($body)) - mb_strlen(trim($rest));
                    $rows[] = $cur = ['dayIdx' => $dayIdx, 'time' => '', 'text' => trim(preg_replace('/\s+/u', ' ', $rest)), 'staff' => ''];
                }
                foreach ($pending as $p) {
                    $rows[] = ['dayIdx' => $dayIdx, 'time' => '', 'text' => $p, 'staff' => ''];
                }
                $pending = [];
                continue;
            }

            // Créneau : « 14h / 18h », « 13h-14h », « 20h », « 21h45 / Minuit »
            if (null !== $dayIdx && preg_match('/^(\s{0,6})('.self::TIME.'(?:\s*[\/\-–à]\s*'.self::TIME.')?)(?:\s{2,}(.*)|\s*)$/ui', $body, $m)) {
                $desc = $m[3] ?? '';
                $descCol = '' === $desc ? mb_strlen($m[1].$m[2]) + 2 : mb_strlen($body) - mb_strlen($desc);
                $staff = [];
                foreach ($staffCols as $k => $col) {
                    if (preg_match('/(?<![\S])([\d?]{1,2})(?![\S])/u', ' '.$str($c, max(0, $col - 2), 4).' ', $sm)) {
                        $staff[] = (['P', 'L', 'S'][$k] ?? '?').' '.$sm[1];
                    }
                }
                $rows[] = $cur = ['dayIdx' => $dayIdx, 'time' => self::time($m[2]), 'text' => trim(preg_replace('/\s+/u', ' ', $desc)), 'staff' => implode(' · ', $staff)];
                continue;
            }

            // Avant le premier jour, après l'en-tête des effectifs : « En amont du jeudi 14h     Pré-montage complet »
            if (null === $dayIdx && $headerAt >= 0 && $n > $headerAt) {
                $parts = preg_split('/\s{3,}/u', trim($body));
                $parts && $pending[] = implode(' : ', $parts);
                continue;
            }

            // Suite de l'activité sur la ligne suivante (même colonne), sans les numéros de page ni les remarques
            $indent = mb_strlen($body) - mb_strlen(ltrim($body));
            if ($cur && '' !== trim($body) && $indent >= $descCol - 3 && preg_match('/\p{L}/u', $body) && !preg_match('/^(Fiche technique|Page \d)/ui', trim($body))) {
                $idx = array_key_last($rows);
                $rows[$idx]['text'] = trim($rows[$idx]['text'].' '.preg_replace('/\s+/u', ' ', trim($body)));
                continue;
            }
            if ($indent < $descCol - 3) {
                $cur = null;
            }
        }
        if (!$rows) {
            throw new \InvalidArgumentException('Aucun créneau lu dans le planning de la fiche.');
        }

        // Dates : mois et année repris des jours voisins (ou de la date), jour de la semaine vérifié
        $this->dates($days, $ev, $warnings);
        $entries = $this->lists->entries('schedule');
        $out = [];
        foreach ($rows as $r) {
            $d = $days[$r['dayIdx']];
            $label = $this->label($r['text'], $entries);
            $out[] = ['day' => $d['date'], 'dayText' => $d['text'], 'time' => $r['time'], 'text' => $r['text'], 'staff' => $r['staff'], 'label' => $label,
                'detail' => mb_substr(Sheet::norm($label) === Sheet::norm($r['text']) ? '' : $r['text'], 0, 255),
                'checked' => '' !== $r['time'] && null !== $d['date']];
        }

        return ['page' => $page + 1, 'pages' => count($pages), 'rows' => $out, 'warnings' => $warnings];
    }

    private function dates(array &$days, Event $ev, array &$warnings): void
    {
        $n = count($days);
        foreach ($days as $i => &$d) {
            // Mois / année absents : ceux du jour daté le plus proche (après, puis avant), sinon ceux de la date
            $ref = null;
            for ($k = 1; $k < $n && !$ref; ++$k) {
                foreach ([$i + $k, $i - $k] as $j) {
                    if (isset($days[$j]) && $days[$j]['month']) {
                        $ref = $days[$j];
                        break;
                    }
                }
            }
            $month = $d['month'] ?? $ref['month'] ?? (int) $ev->firstDate->format('n');
            $year = $d['year'] ?? $ref['year'] ?? (int) $ev->firstDate->format('Y');
            $date = checkdate($month, $d['day'], $year) ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d['day'])) : null;
            if ($date && (int) $date->format('N') !== $d['weekday']) {
                // Coquille dans la fiche (« Vendredi 29 nov » un dimanche) : le bon jour de la semaine qui suit le jour précédent
                $prev = $i > 0 && $days[$i - 1]['date'] ? new \DateTimeImmutable($days[$i - 1]['date']) : null;
                $fixed = null;
                if ($prev) {
                    for ($k = 1; $k <= 7 && !$fixed; ++$k) {
                        (int) $prev->modify("+$k days")->format('N') === $d['weekday'] && $fixed = $prev->modify("+$k days");
                    }
                }
                $warnings[] = sprintf('« %s » : le %d %s %d est un %s. %s', $d['text'], $d['day'], self::MONTH_NAMES[$month], $year, self::WEEKDAYS[(int) $date->format('N')],
                    $fixed ? sprintf('Date corrigée en %s %d %s, à vérifier.', self::WEEKDAYS[$d['weekday']], (int) $fixed->format('j'), self::MONTH_NAMES[(int) $fixed->format('n')]) : 'Vérifiez la date.');
                $date = $fixed ?? $date;
            }
            $d['date'] = $date?->format('Y-m-d');
            if ($date && ($date < $ev->start()->modify('-10 days') || $date > $ev->end()->modify('+10 days'))) {
                $far[] = $date;
            }
        }
        unset($d);
        if ($far ?? []) {
            $warnings[] = sprintf('Le planning de la fiche (%s) est loin de cette date de tournée (%s au %s) : est-ce la bonne fiche ?',
                min($far) == max($far) ? min($far)->format('d/m/Y') : min($far)->format('d/m/Y').' au '.max($far)->format('d/m/Y'), $ev->start()->format('d/m/Y'), $ev->end()->format('d/m/Y'));
        }
    }

    /** Intitulé de la liste le plus proche de l'activité ; « » si aucun (à choisir dans l'aperçu). */
    private function label(string $text, array $entries): string
    {
        $norm = Sheet::norm($text);
        $exists = function (string $label) use ($entries): ?string {
            foreach ($entries as $e) {
                if (Lists::key($e) === Lists::key($label)) {
                    return $e;
                }
            }

            return null;
        };
        // Le mot-clé qui apparaît le premier dans l'activité l'emporte (« Démontage tout de suite après la représentation » :
        // Démontage) ; arrivée, départ et transfert seulement à défaut d'autre chose
        $best = null;
        foreach (array_keys(self::RULES) as $rank => $re) {
            if (preg_match('/'.$re.'/u', $norm, $m, PREG_OFFSET_CAPTURE)) {
                $weak = in_array($re, ['transfert', 'arrivee', 'depart'], true);
                $key = [$weak, $m[0][1], $rank];
                foreach (self::RULES[$re] as $l) {
                    if (($found = $exists($l)) && (null === $best || $key < $best[0])) {
                        $best = [$key, $found];
                        break;
                    }
                }
            }
        }
        if ($best) {
            return $best[1];
        }

        return $this->lists->match('schedule', $text)['label'] ?? '';
    }

    private static function month(string $s): ?int
    {
        $n = Sheet::norm($s);
        foreach (self::MONTHS as $k => $m) {
            if (str_starts_with($n, $k) && mb_strlen($n) <= 9) {
                return $m;
            }
        }

        return null;
    }

    /** « 09h / 13h » → « 09h–13h » ; « 21h45 / Minuit » → « 21h45–minuit ». */
    private static function time(string $t): string
    {
        $t = preg_replace('/\s*[\/\-–à]\s*/u', '–', mb_strtolower(preg_replace('/\s+/u', ' ', trim($t))));

        return mb_substr(str_replace(' ', '', $t), 0, 20);
    }
}
