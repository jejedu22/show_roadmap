<?php

namespace App\Service;

use App\Entity\Event;

/**
 * Avancement de la préparation d'une date, en 6 étapes dans l'ordre de l'organisation,
 * et liste de ce qui reste à faire. Même calcul pour le tableau de bord et la fiche.
 */
class Readiness
{
    public function __construct(private QuoteCalculator $quotes)
    {
    }

    /** @return array{steps: list<array{key: string, label: string, done: bool, detail: string}>, done: int, total: int, todo: list<array{label: string, step: string}>, legsCovered: int, legsNeeded: int} */
    public function compute(Event $ev): array
    {
        $people = $ev->participations->count();
        $nights = 0;
        $withoutLodging = [];
        $missingLegs = [];
        $covered = 0;
        foreach ($ev->participations as $p) {
            $personNights = 0;
            foreach ($p->days as $d) {
                $personNights += $d->night;
            }
            $nights += $personNights;
            if ($personNights > 0 && !$p->lodging) {
                $withoutLodging[] = $p->person->firstName ?: $p->person->lastName;
            }
            foreach (['outbound' => 'l\'aller', 'return' => 'le retour'] as $dir => $label) {
                if ($p->legs->exists(fn ($k, $l) => $l->direction === $dir)) {
                    ++$covered;
                } else {
                    $missingLegs[] = ['label' => 'Réserver '.$label.' de '.($p->person->firstName ?: $p->person->lastName), 'step' => 'travel'];
                }
            }
        }
        $quote = $this->quotes->compute($ev);
        $hasInfo = '' !== trim($ev->address) || '' !== trim($ev->venueContact);
        $stayDone = $people > 0 && $ev->lodgings->count() > 0 && !$withoutLodging;
        $fmt = fn (float $x) => number_format($x, 0, ',', "\u{202F}")."\u{a0}€";

        $steps = [
            ['key' => 'info', 'label' => 'Infos', 'done' => $hasInfo, 'detail' => $hasInfo ? 'Lieu et contacts' : 'Adresse et contacts à saisir'],
            ['key' => 'team', 'label' => 'Équipe', 'done' => $people > 0, 'detail' => $people ? $people.' personne'.($people > 1 ? 's' : '') : 'Personne pour l\'instant'],
            ['key' => 'stay', 'label' => 'Séjour', 'done' => $stayDone,
                'detail' => $stayDone ? $nights.' nuitée'.($nights > 1 ? 's' : '') : ($ev->lodgings->isEmpty() ? 'Hébergement à ajouter' : 'Hébergement à attribuer')],
            ['key' => 'travel', 'label' => 'Voyages', 'done' => $people > 0 && $covered === 2 * $people, 'detail' => $covered.' / '.(2 * $people).' trajets'],
            ['key' => 'quote', 'label' => 'Devis', 'done' => (float) $ev->fee > 0, 'detail' => (float) $ev->fee > 0 ? $fmt($quote['totalTtc']).' TTC' : 'Cachet à saisir'],
            ['key' => 'roadmap', 'label' => 'Feuille de route', 'done' => null !== $ev->sharedAt,
                'detail' => $ev->sharedAt ? 'Partagée le '.$ev->sharedAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m') : 'Pas encore partagée'],
        ];

        $todo = [];
        if (!$hasInfo) {
            $todo[] = ['label' => 'Saisir l\'adresse et les contacts du lieu', 'step' => 'info'];
        }
        if (!$people) {
            $todo[] = ['label' => 'Composer l\'équipe', 'step' => 'team'];
        }
        if ($people && $ev->lodgings->isEmpty()) {
            $todo[] = ['label' => 'Ajouter un hébergement', 'step' => 'stay'];
        } elseif ($withoutLodging) {
            $todo[] = ['label' => 'Attribuer un hébergement à '.implode(', ', $withoutLodging), 'step' => 'stay'];
        }
        array_push($todo, ...$missingLegs);
        if ((float) $ev->fee <= 0) {
            $todo[] = ['label' => 'Saisir le cachet du devis', 'step' => 'quote'];
        }
        if (null === $ev->sharedAt && $people) {
            $todo[] = ['label' => 'Partager la feuille de route avec l\'équipe', 'step' => 'roadmap'];
        }

        return ['steps' => $steps, 'done' => count(array_filter($steps, fn ($s) => $s['done'])), 'total' => count($steps), 'todo' => $todo,
            'legsCovered' => $covered, 'legsNeeded' => 2 * $people];
    }
}
