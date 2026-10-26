<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lecture tolérante d'un onglet : valeurs mises en cache par Excel pour les formules (=DEVIS!A16),
 * dates reconnues, recherche de cellules par libellé (les lignes varient d'un fichier à l'autre).
 */
final class Sheet
{
    public function __construct(private Worksheet $ws)
    {
    }

    public function value(int $col, int $row): mixed
    {
        if (!$this->ws->cellExists([$col, $row])) {
            return null;
        }
        $cell = $this->ws->getCell([$col, $row]);
        $v = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if (is_string($v) && str_starts_with($v, '#')) { // #REF!, #VALUE!…
            return null;
        }
        if ((is_int($v) || is_float($v)) && $this->isDate($cell)) {
            return \DateTimeImmutable::createFromInterface(Date::excelToDateTimeObject($v))->setTime(0, 0);
        }

        return $v;
    }

    public function text(int $col, int $row): string
    {
        $v = $this->value($col, $row);

        return $v instanceof \DateTimeImmutable ? '' : trim(preg_replace('/[ \t\x{a0}]+/u', ' ', (string) $v));
    }

    public function date(int $col, int $row): ?\DateTimeImmutable
    {
        $v = $this->value($col, $row);

        return $v instanceof \DateTimeImmutable ? $v : null;
    }

    public function number(int $col, int $row): ?float
    {
        $v = $this->value($col, $row);

        return is_int($v) || is_float($v) ? (float) $v : (is_string($v) && is_numeric(str_replace(',', '.', trim($v))) ? (float) str_replace(',', '.', trim($v)) : null);
    }

    /** Premier lien web (http/https) posé sur une des cellules de la ligne, colonnes $from à $to. */
    public function link(int $row, int $from = 1, int $to = 10): string
    {
        for ($c = $from; $c <= $to; ++$c) {
            if ($this->ws->cellExists([$c, $row]) && ($cell = $this->ws->getCell([$c, $row]))->hasHyperlink()) {
                $url = trim($cell->getHyperlink()->getUrl());
                if (preg_match('#^https?://\S+$#i', $url) && mb_strlen($url) <= 1000) {
                    return $url;
                }
            }
        }

        return '';
    }

    public function maxRow(): int
    {
        return min($this->ws->getHighestDataRow(), 2000);
    }

    /** Première cellule dont le texte commence par $label (sans accents ni casse), dans une zone donnée. */
    public function find(string $label, int $fromRow = 1, ?int $col = null, int $maxCol = 30): ?array
    {
        $needle = self::norm($label);
        for ($r = $fromRow; $r <= $this->maxRow(); ++$r) {
            foreach ($col ? [$col] : range(1, $maxCol) as $c) {
                $t = $this->text($c, $r);
                if ('' !== $t && str_starts_with(self::norm($t), $needle)) {
                    return [$c, $r];
                }
            }
        }

        return null;
    }

    /** Minuscules, sans accents ni espaces superflus. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'ñ' => 'n', '’' => "'"]);

        return preg_replace('/\s+/', ' ', $s);
    }

    private function isDate(Cell $cell): bool
    {
        try {
            return Date::isDateTime($cell);
        } catch (\Throwable) {
            return false;
        }
    }
}
