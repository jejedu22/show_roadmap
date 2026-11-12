<?php

namespace App\Tests;

use App\Service\PdfRenderer;
use PHPUnit\Framework\TestCase;

/** Feuille de route PDF : trajets d'un sens groupés par date, puis par personne (correspondances ensemble, dans l'ordre horaire). */
class PdfTripsTest extends TestCase
{
    public function testTripsGroupedByDateThenPerson(): void
    {
        $leg = fn ($dir, $day, $time) => ['direction' => $dir, 'day' => $day, 'depTime' => $time];
        $part = fn ($name, $legs) => ['person' => ['fullName' => $name], 'legs' => $legs];
        $ev = ['participations' => [
            $part('Zoé', [$leg('outbound', '2027-01-18', '14h00'), $leg('outbound', '2027-01-18', '9h05'), $leg('return', '2027-01-21', '10h')]),
            $part('Anna', [$leg('outbound', '2027-01-18', '11h00')]),
            $part('Marc', [$leg('outbound', '2027-01-17', '16h00')]),
            $part('Luc', [$leg('return', '2027-01-20', '18h')]),
        ]];
        $travel = (new \ReflectionMethod(PdfRenderer::class, 'travel'))->getClosure();
        $names = fn (array $people) => array_map(fn ($g) => $g['p']['person']['fullName'], $people);

        $out = $travel($ev, 'outbound');
        self::assertSame(['2027-01-17', '2027-01-18'], array_keys($out), 'jours dans l\'ordre');
        self::assertSame(['Marc'], $names($out['2027-01-17']));
        self::assertSame(['Zoé', 'Anna'], $names($out['2027-01-18']), 'par premier départ du jour ; Luc sans aller');
        self::assertSame(['9h05', '14h00'], array_column($out['2027-01-18'][0]['legs'], 'depTime'), 'correspondance dans l\'ordre');

        $back = $travel($ev, 'return');
        self::assertSame(['2027-01-20', '2027-01-21'], array_keys($back));
        self::assertSame(['Luc'], $names($back['2027-01-20']));
        self::assertSame(['Zoé'], $names($back['2027-01-21']));
    }
}
