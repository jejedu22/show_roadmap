<?php

namespace App\Service;

use App\Entity\Event;

/** Devis d'une date, repris des onglets DEVIS / Défraiements / Suivi frais des classeurs Excel. */
class QuoteCalculator
{
    public function compute(Event $ev): array
    {
        $meals = $bf = $nights = 0;
        $tickets = 0.0;
        foreach ($ev->participations as $p) {
            foreach ($p->days as $d) {
                $meals += $d->meals;
                $bf += $d->breakfast;
                $nights += $d->night;
            }
            foreach ($p->legs as $l) {
                $tickets += (float) $l->price;
            }
        }
        $teamExtras = $otherExtras = 0.0;
        foreach ($ev->extras as $x) {
            'team_transport' === $x->kind ? $teamExtras += (float) $x->amount : $otherExtras += (float) $x->amount;
        }
        $km = 0.0;
        foreach ($ev->trucks as $t) {
            $km += (float) $t->km;
        }
        $truck = $km * (float) $ev->truckRate;
        $mealsAmount = $meals * (float) $ev->mealRate;
        $bfAmount = $bf * (float) $ev->breakfastRate;
        $nightsAmount = $nights * (float) $ev->nightRate;
        $team = $tickets + $teamExtras;
        $annex = $truck + $team + $mealsAmount + $bfAmount + $nightsAmount + $otherExtras;
        $totalHt = (float) $ev->fee + $annex;
        $vat = $totalHt * (float) $ev->vatRate / 100;
        $r = fn (float $x) => round($x, 2);

        return [
            'fee' => $r((float) $ev->fee),
            'truckKm' => $km,
            'truckScale' => self::truckScale($km, $ev->performances),
            'travelTickets' => $r($tickets),
            'mealsCount' => $meals,
            'breakfastsCount' => $bf,
            'nightsCount' => $nights,
            'meals' => $r($mealsAmount),
            'breakfasts' => $r($bfAmount),
            'nights' => $r($nightsAmount),
            'lines' => [
                ['label' => sprintf('Transport décor (%s km × %s €)', self::n($km), self::n((float) $ev->truckRate)), 'amount' => $r($truck)],
                ['label' => 'Transport équipe (billets + transports locaux)', 'amount' => $r($team)],
                ['label' => sprintf('Défraiements repas (%d × %s €)', $meals, self::n((float) $ev->mealRate)), 'amount' => $r($mealsAmount)],
                ['label' => sprintf('Petits-déjeuners (%d × %s €)', $bf, self::n((float) $ev->breakfastRate)), 'amount' => $r($bfAmount)],
                ['label' => sprintf('Nuitées (%d × %s €)', $nights, self::n((float) $ev->nightRate)), 'amount' => $r($nightsAmount)],
                ['label' => 'Autres frais', 'amount' => $r($otherExtras)],
            ],
            'annexTotal' => $r($annex),
            'totalHt' => $r($totalHt),
            'vat' => $r($vat),
            'totalTtc' => $r($totalHt + $vat),
        ];
    }

    /** Par personne : repas, petits-déjeuners, défraiement et billets aller / retour. */
    public function personCosts(Event $ev): array
    {
        $rows = [];
        foreach ($ev->participations as $p) {
            $meals = $bf = $nights = 0;
            foreach ($p->days as $d) {
                $meals += $d->meals;
                $bf += $d->breakfast;
                $nights += $d->night;
            }
            $out = $ret = 0.0;
            foreach ($p->legs as $l) {
                'return' === $l->direction ? $ret += (float) $l->price : $out += (float) $l->price;
            }
            $rows[] = [
                'participationId' => $p->id, 'name' => $p->person->fullName(), 'departureCity' => $p->departureCity,
                'meals' => $meals, 'breakfasts' => $bf, 'nights' => $nights,
                'perDiem' => round($meals * (float) $ev->mealRate + $bf * (float) $ev->breakfastRate, 2),
                'outbound' => round($out, 2), 'return' => round($ret, 2),
            ];
        }

        return $rows;
    }

    /** Barème indicatif du mémo « Transport décor » des devis. */
    public static function truckScale(float $km, int $performances): string
    {
        if ($km <= 0) {
            return '';
        }
        if ($km <= 350) {
            $forfait = [1 => 600, 2 => 650][$performances] ?? 700;

            return "≤ 350 km : forfait $forfait € HT ($performances représentation(s))";
        }
        if ($km <= 750) {
            return '350–750 km : 1,30 € HT/km → '.self::n(round($km * 1.3, 2)).' € HT';
        }

        return '750–1000 km : 1,10 € HT/km → '.self::n(round($km * 1.1, 2)).' € HT';
    }

    private static function n(float $x): string
    {
        return rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
    }
}
