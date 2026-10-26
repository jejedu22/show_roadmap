<?php

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Lecture tolérante des valeurs envoyées par le front (virgule décimale, chaînes vides…). */
final class Input
{
    public static function str(array $d, string $k, int $max = 255): string
    {
        $v = $d[$k] ?? '';

        return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
    }

    public static function text(array $d, string $k): string
    {
        $v = $d[$k] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    /** Lien http(s) uniquement : un « javascript: » ou « data: » serait exécutable depuis la page ou le PDF. */
    public static function url(array $d, string $k): string
    {
        $v = self::str($d, $k, 1000);
        if ('' === $v) {
            return '';
        }
        if (!preg_match('~^https?://[^\s<>"]+$~i', $v) || false === filter_var($v, FILTER_VALIDATE_URL)) {
            throw new BadRequestHttpException("Lien invalide (il doit commencer par https://) : $v");
        }

        return $v;
    }

    public static function date(array $d, string $k): ?\DateTimeImmutable
    {
        $v = trim((string) ($d[$k] ?? ''));
        if ('' === $v) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$date || $date->format('Y-m-d') !== $v) {
            throw new BadRequestHttpException("Date invalide : $v");
        }

        return $date;
    }

    public static function decimal(array $d, string $k, int $scale = 2): string
    {
        return number_format(self::float($d, $k), $scale, '.', '');
    }

    public static function float(array $d, string $k): float
    {
        $v = str_replace([',', ' ', "\u{a0}"], ['.', '', ''], trim((string) ($d[$k] ?? '')));
        if ('' === $v) {
            return 0.0;
        }
        if (!is_numeric($v)) {
            throw new BadRequestHttpException("Nombre invalide : $v");
        }

        return (float) $v;
    }

    public static function int(array $d, string $k, int $default = 0): int
    {
        $v = $d[$k] ?? null;

        return (null === $v || '' === $v) ? $default : (int) self::float($d, $k);
    }

    public static function iso(?\DateTimeInterface $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    /** « 9h10 », « 14h », « 9:05 – 13h » → minutes depuis minuit (heures absentes en dernier), pour trier sans ordre alphabétique. */
    public static function minutes(string $t): int
    {
        return preg_match('/^\s*(\d{1,2})\s*[h:]\s*(\d{0,2})/i', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : 1440;
    }
}
