<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\Person;
use App\Entity\User;

/** Représentation JSON des entités pour le front. */
class Presenter
{
    public function __construct(private Planning $planning, private QuoteCalculator $quotes)
    {
    }

    public function user(User $u): array
    {
        return ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'isAdmin' => $u->isAdmin, 'isActive' => $u->isActive,
            'companies' => $u->companies->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->getValues()];
    }

    public function person(Person $p): array
    {
        return ['id' => $p->id, 'lastName' => $p->lastName, 'firstName' => $p->firstName, 'fullName' => $p->fullName(),
            'function' => $p->function, 'phone' => $p->phone, 'email' => $p->email, 'diet' => $p->diet, 'homeCity' => $p->homeCity];
    }

    public function eventSummary(Event $e): array
    {
        return ['id' => $e->id, 'showId' => $e->show->id, 'showTitle' => $e->show->title, 'venue' => $e->venue, 'city' => $e->city,
            'status' => $e->status, 'firstDate' => Input::iso($e->firstDate), 'lastDate' => Input::iso($e->lastDate),
            'start' => Input::iso($e->start()), 'end' => Input::iso($e->end()), 'teamSize' => $e->participations->count()];
    }

    public function company(Company $c): array
    {
        $events = $this->planning->companyEvents($c);
        $cancelled = [];
        foreach ($c->shows as $s) {
            foreach ($s->events as $e) {
                if ('cancelled' === $e->status) {
                    $cancelled[] = $e;
                }
            }
        }

        return [
            'id' => $c->id, 'name' => $c->name, 'notes' => $c->notes,
            'shows' => $c->shows->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'description' => $s->description,
                'eventCount' => $s->events->count()])->getValues(),
            'people' => $c->people->map(fn (Person $p) => $this->person($p))->getValues(),
            'events' => array_map(fn (Event $e) => $this->eventSummary($e) + [
                'overlaps' => array_map(fn (Event $o) => ['id' => $o->id, 'showTitle' => $o->show->title, 'venue' => $o->venue],
                    array_values(array_filter($events, fn (Event $o) => $e->overlaps($o)))),
            ], $events),
            'cancelledEvents' => array_map(fn (Event $e) => $this->eventSummary($e), $cancelled),
        ];
    }

    public function event(Event $e): array
    {
        $conflicts = $this->planning->personConflicts($e);
        $used = [];
        foreach ($e->participations as $p) {
            $used[$p->person->id] = true;
        }
        $dec = fn (string $x) => (float) $x;

        return $this->eventSummary($e) + [
            'companyId' => $e->show->company->id, 'companyName' => $e->show->company->name,
            'address' => $e->address, 'venueContact' => $e->venueContact, 'notes' => $e->notes,
            'techArrival' => Input::iso($e->techArrival), 'artisticArrival' => Input::iso($e->artisticArrival),
            'departure' => Input::iso($e->departure), 'performances' => $e->performances,
            'rates' => ['mealRate' => $dec($e->mealRate), 'breakfastRate' => $dec($e->breakfastRate), 'nightRate' => $dec($e->nightRate),
                'truckRate' => $dec($e->truckRate), 'vatRate' => $dec($e->vatRate), 'fee' => $dec($e->fee),
                'lighting' => $dec($e->lighting), 'posters' => $dec($e->posters)],
            'dates' => array_map(fn ($d) => $d->format('Y-m-d'), $e->spanDates()),
            'participations' => $e->participations->map(fn ($p) => [
                'id' => $p->id, 'person' => $this->person($p->person), 'role' => $p->role, 'roomType' => $p->roomType,
                'departureCity' => $p->departureCity, 'lodgingId' => $p->lodging?->id, 'note' => $p->note,
                'days' => array_combine(
                    $p->days->map(fn ($d) => $d->day->format('Y-m-d'))->getValues(),
                    $p->days->map(fn ($d) => ['night' => $d->night, 'meals' => $d->meals, 'breakfast' => $d->breakfast])->getValues(),
                ) ?: new \stdClass(),
                'legs' => $p->legs->map(fn ($l) => ['id' => $l->id, 'direction' => $l->direction, 'day' => Input::iso($l->day),
                    'origin' => $l->origin, 'destination' => $l->destination, 'depTime' => $l->depTime, 'arrTime' => $l->arrTime,
                    'mode' => $l->mode, 'reference' => $l->reference, 'price' => (float) $l->price,
                    'bookedOn' => Input::iso($l->bookedOn), 'note' => $l->note])->getValues(),
                'conflicts' => array_map(fn (Event $o) => ['id' => $o->id, 'showTitle' => $o->show->title, 'venue' => $o->venue],
                    $conflicts[$p->person->id] ?? []),
            ])->getValues(),
            'availablePeople' => array_values(array_map(fn (Person $p) => $this->person($p),
                array_filter($e->show->company->people->toArray(), fn (Person $p) => !isset($used[$p->id])))),
            'lodgings' => $e->lodgings->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'address' => $l->address,
                'phone' => $l->phone, 'details' => $l->details])->getValues(),
            'trucks' => $e->trucks->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'km' => (float) $t->km])->getValues(),
            'extras' => $e->extras->map(fn ($x) => ['id' => $x->id, 'label' => $x->label, 'amount' => (float) $x->amount,
                'kind' => $x->kind])->getValues(),
            'sections' => $e->sections->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'body' => $s->body,
                'position' => $s->position])->getValues(),
            'schedule' => $e->schedule->map(fn ($s) => ['id' => $s->id, 'day' => Input::iso($s->day), 'time' => $s->time,
                'label' => $s->label])->getValues(),
            'overlaps' => array_map(fn (Event $o) => $this->eventSummary($o), $this->planning->overlaps($e)),
            'quote' => $this->quotes->compute($e),
            'personCosts' => $this->quotes->personCosts($e),
        ];
    }
}
