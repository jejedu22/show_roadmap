<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Day;
use App\Entity\Event;
use App\Entity\Participation;
use Doctrine\ORM\EntityManagerInterface;

/** Chevauchements entre dates et pré-remplissage des grilles de séjour. */
class Planning
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<Event> dates non annulées de la compagnie, par date */
    public function companyEvents(Company $company): array
    {
        return $this->em->createQuery(
            'SELECT e, s FROM App\Entity\Event e JOIN e.show s WHERE s.company = :c AND e.status != :x ORDER BY e.firstDate'
        )->setParameters(['c' => $company, 'x' => 'cancelled'])->getResult();
    }

    /** @return list<Event> */
    public function overlaps(Event $event): array
    {
        return array_values(array_filter($this->companyEvents($event->show->company), fn (Event $o) => $event->overlaps($o)));
    }

    /** @return array<int, list<Event>> person_id => autres dates simultanées où la personne est aussi engagée */
    public function personConflicts(Event $event): array
    {
        $mine = [];
        foreach ($event->participations as $p) {
            $mine[$p->person->id] = true;
        }
        $out = [];
        foreach ($this->overlaps($event) as $o) {
            foreach ($o->participations as $p) {
                if (isset($mine[$p->person->id])) {
                    $out[$p->person->id][] = $o;
                }
            }
        }

        return $out;
    }

    /** Midi : avant (ou à) 12h00, le déjeuner du jour compte à l'arrivée ; après 12h00, il compte au départ. */
    private const NOON = 12 * 60;

    /**
     * Nuitées, repas et petits-déjeuners d'un participant, d'après ses voyages :
     * - arrivée : celle du dernier trajet aller ; arrivée après 12h00 → dîner seulement, sinon déjeuner et dîner ;
     * - départ : celui du premier trajet retour ; départ après 12h00 → déjeuner, sinon aucun repas ce jour-là ;
     * - nuitées de la nuit d'arrivée à la veille du départ ; petit-déjeuner chaque matin après une nuitée.
     * Sans trajet (ou sans horaire), les dates de la fiche : arrivée technique / artistique, départ.
     * La grille du participant doit être vide (pour réinitialiser : vider, flush, puis appeler).
     */
    public function fillDefaultDays(Participation $part): void
    {
        [$arrival, $arrivalLunch] = $this->arrival($part);
        [$departure, $departureLunch] = $this->departure($part);
        if ($departure < $arrival) {
            $departure = $arrival;
        }
        for ($d = $arrival; $d <= $departure; $d = $d->modify('+1 day')) {
            $meals = match (true) {
                $d == $arrival && $d == $departure => $arrivalLunch && $departureLunch ? 1 : 0,
                $d == $arrival => $arrivalLunch ? 2 : 1,
                $d == $departure => $departureLunch ? 1 : 0,
                default => 2,
            };
            $part->days->add(new Day($part, $d, $d < $departure ? 1 : 0, $meals, $d == $arrival ? 0 : 1));
        }
    }

    /** @return array{0: \DateTimeImmutable, 1: bool} jour d'arrivée, déjeuner compté ce jour-là */
    private function arrival(Participation $part): array
    {
        $ev = $part->event;
        $legs = array_filter($part->legs->toArray(), fn ($l) => 'outbound' === $l->direction);
        if ($legs) {
            // Dernier trajet aller (correspondances comprises) : son heure d'arrivée, à défaut son heure de départ
            $at = fn ($l) => Input::minutes($l->arrTime ?: $l->depTime);
            usort($legs, fn ($a, $b) => [$a->day, $at($a)] <=> [$b->day, $at($b)]);
            $last = end($legs);
            $time = $at($last);

            return [$last->day, 1440 === $time ? $last->day >= $ev->firstDate : $time <= self::NOON];
        }
        $role = mb_strtolower($part->role);
        $tech = str_contains($role, 'régi') || str_contains($role, 'techni');
        $day = ($tech ? $ev->techArrival : $ev->artisticArrival) ?? $ev->start();

        return [$day, $day >= $ev->firstDate];
    }

    /** @return array{0: \DateTimeImmutable, 1: bool} jour de départ, déjeuner compté ce jour-là */
    private function departure(Participation $part): array
    {
        $ev = $part->event;
        $legs = array_filter($part->legs->toArray(), fn ($l) => 'return' === $l->direction);
        if ($legs) {
            // Premier trajet retour : son heure de départ
            usort($legs, fn ($a, $b) => [$a->day, Input::minutes($a->depTime)] <=> [$b->day, Input::minutes($b->depTime)]);
            $first = reset($legs);
            $time = Input::minutes($first->depTime);

            return [$first->day, 1440 === $time || $time > self::NOON];
        }

        return [$ev->departure ?? $ev->lastDate, true];
    }
}
