<?php

namespace App\Tests;

use App\Service\PdfRenderer;
use PHPUnit\Framework\TestCase;

/** Feuille de route PDF : trajets d'un sens regroupés par personne, chacun dans l'ordre chronologique. */
class PdfTripsTest extends TestCase
{
    public function testTripsGroupedByPerson(): void
    {
        $leg = fn ($dir, $day, $time) => ['direction' => $dir, 'day' => $day, 'depTime' => $time];
        $part = fn ($name, $legs) => ['person' => ['fullName' => $name], 'legs' => $legs];
        $ev = ['participations' => [
            $part('Zoé', [$leg('outbound', '2027-01-18', '14h00'), $leg('outbound', '2027-01-18', '9h05'), $leg('return', '2027-01-21', '10h')]),
            $part('Anna', [$leg('outbound', '2027-01-18', '11h00')]),
            $part('Luc', [$leg('return', '2027-01-20', '18h')]),
        ]];
        $travel = (new \ReflectionMethod(PdfRenderer::class, 'travel'))->getClosure();

        $out = $travel($ev, 'outbound');
        self::assertSame(['Zoé', 'Anna'], array_map(fn ($g) => $g['p']['person']['fullName'], $out), 'par premier départ ; Luc sans aller');
        self::assertSame(['9h05', '14h00'], array_column($out[0]['legs'], 'depTime'), 'correspondance dans l\'ordre');
        self::assertSame(['Luc', 'Zoé'], array_map(fn ($g) => $g['p']['person']['fullName'], $travel($ev, 'return')));
    }
}
