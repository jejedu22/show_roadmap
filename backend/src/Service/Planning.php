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

    /**
     * Valeurs par défaut : nuits de l'arrivée à la veille du départ, 2 repas/jour (1 à l'arrivée et au départ).
     * La grille du participant doit être vide (pour réinitialiser : vider, flush, puis appeler).
     */
    public function fillDefaultDays(Participation $part): void
    {
        $ev = $part->event;
        $role = mb_strtolower($part->role);
        $tech = str_contains($role, 'régi') || str_contains($role, 'techni');
        $arrival = ($tech ? $ev->techArrival : $ev->artisticArrival) ?? $ev->start();
        $departure = $ev->departure ?? $ev->lastDate;
        for ($d = $arrival; $d <= $departure; $d = $d->modify('+1 day')) {
            $meals = match (true) {
                $d == $arrival => $d < $ev->firstDate ? 1 : 2,
                $d == $departure => 1,
                default => 2,
            };
            $part->days->add(new Day($part, $d, $d < $departure ? 1 : 0, $meals, $d == $arrival ? 0 : 1));
        }
    }
}
