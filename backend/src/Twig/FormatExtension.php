<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Mise en forme française des dates et montants pour les documents PDF (mêmes règles que le front). */
class FormatExtension extends AbstractExtension
{
    private const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    private const DAYS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const MONTHS_LONG = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function getFilters(): array
    {
        return [
            new TwigFilter('fdate', $this->fdate(...)),
            new TwigFilter('fday', $this->fday(...)),
            new TwigFilter('fperiod', $this->fperiod(...)),
            new TwigFilter('money', fn (?float $x) => number_format($x ?? 0, 2, ',', "\u{202F}")."\u{a0}€"),
            new TwigFilter('ddmm', fn (string $iso) => substr($iso, 8, 2).'/'.substr($iso, 5, 2)),
            new TwigFilter('num', fn (float $x) => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',')),
        ];
    }

    public function fdate(?string $iso, bool $short = false): string
    {
        if (!$iso) {
            return '';
        }
        $d = new \DateTimeImmutable($iso);
        $s = self::DAYS[(int) $d->format('w')].' '.$d->format('j').' '.self::MONTHS[(int) $d->format('n') - 1];

        return $short ? $s : $s.' '.$d->format('Y');
    }

    /** « lundi 18 mai » */
    public function fday(string $iso): string
    {
        $d = new \DateTimeImmutable($iso);

        return ucfirst(self::DAYS_LONG[(int) $d->format('w')]).' '.$d->format('j').' '.self::MONTHS_LONG[(int) $d->format('n') - 1];
    }

    public function fperiod(string $a, ?string $b): string
    {
        $x = new \DateTimeImmutable($a);
        if (!$b || $a === $b) {
            return $x->format('j').' '.self::MONTHS_LONG[(int) $x->format('n') - 1].' '.$x->format('Y');
        }
        $y = new \DateTimeImmutable($b);
        if ($x->format('Y-m') === $y->format('Y-m')) {
            return $x->format('j').' au '.$y->format('j').' '.self::MONTHS_LONG[(int) $y->format('n') - 1].' '.$y->format('Y');
        }

        return $x->format('j').' '.self::MONTHS_LONG[(int) $x->format('n') - 1].' au '.$y->format('j').' '.self::MONTHS_LONG[(int) $y->format('n') - 1].' '.$y->format('Y');
    }
}
