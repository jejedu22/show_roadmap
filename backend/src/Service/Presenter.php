<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\Person;
use App\Entity\User;
use App\Security\Authz;
use App\Security\Permissions;

/** Représentation JSON des entités pour le front. */
class Presenter
{
    public function __construct(private Planning $planning, private QuoteCalculator $quotes, private Readiness $readiness, private Authz $authz)
    {
    }

    /** Droits de l'utilisateur sur la compagnie ; sans utilisateur (PDF partagé, export interne) : tout. */
    private function perms(Company $c, ?User $user): array
    {
        return $user ? $this->authz->companyPerms($user, $c) : array_keys(Permissions::COMPANY);
    }

    /** Retire les coordonnées personnelles sans le droit « Voir les coordonnées ». */
    private function personFor(Person $p, array $perms): array
    {
        $data = $this->person($p);

        return in_array('people.view', $perms, true) ? $data : ['phone' => '', 'email' => '', 'diet' => ''] + $data;
    }

    /** Avancement sans les montants pour qui ne voit pas les finances. */
    private function readinessFor(Event $e, array $perms): array
    {
        $r = $this->readiness->compute($e);
        if (!in_array('finance.view', $perms, true)) {
            $r['steps'] = array_values(array_filter($r['steps'], fn ($s) => 'quote' !== $s['key']));
            $r['todo'] = array_values(array_filter($r['todo'], fn ($t) => 'quote' !== $t['step']));
            $r['done'] = count(array_filter($r['steps'], fn ($s) => $s['done']));
            $r['total'] = count($r['steps']);
        }
        if (!in_array('share.manage', $perms, true)) {
            $r['todo'] = array_values(array_filter($r['todo'], fn ($t) => 'roadmap' !== $t['step']));
        }

        return $r;
    }

    /** Membres de l'équipe d'une date, pour les pastilles (initiales). */
    private function team(Event $e): array
    {
        return $e->participations->map(fn ($p) => ['id' => $p->person->id, 'name' => trim($p->person->firstName.' '.$p->person->lastName),
            'initials' => mb_strtoupper(mb_substr($p->person->firstName, 0, 1).mb_substr($p->person->lastName, 0, 1))])->getValues();
    }

    /** Utilisateur connecté : son profil et ses droits d'administration. */
    public function user(User $u): array
    {
        return ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'isAdmin' => $u->isAdmin, 'isActive' => $u->isActive,
            'permissions' => $this->authz->globalPerms($u),
            'groups' => $u->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->getValues()];
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

    public function company(Company $c, ?User $user = null): array
    {
        $perms = $this->perms($c, $user);
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
            'id' => $c->id, 'name' => $c->name, 'notes' => $c->notes, 'permissions' => $perms,
            'shows' => $c->shows->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'description' => $s->description,
                'eventCount' => $s->events->count()])->getValues(),
            'people' => $c->people->map(fn (Person $p) => $this->personFor($p, $perms))->getValues(),
            'events' => array_map(function (Event $e) use ($events, $perms) {
                $overlaps = array_values(array_filter($events, fn (Event $o) => $e->overlaps($o)));
                $mine = $e->participations->map(fn ($p) => $p->person->id)->toArray();
                $conflicts = [];
                foreach ($overlaps as $o) {
                    foreach ($o->participations as $p) {
                        if (in_array($p->person->id, $mine, true)) {
                            $conflicts[$p->person->id] = $p->person->firstName ?: $p->person->lastName;
                        }
                    }
                }

                return $this->eventSummary($e) + [
                    'overlaps' => array_map(fn (Event $o) => ['id' => $o->id, 'showTitle' => $o->show->title, 'venue' => $o->venue], $overlaps),
                    'conflicts' => array_values($conflicts),
                    'team' => $this->team($e),
                    'readiness' => $this->readinessFor($e, $perms),
                ];
            }, $events),
            'cancelledEvents' => array_map(fn (Event $e) => $this->eventSummary($e), $cancelled),
        ];
    }

    public function event(Event $e, ?User $user = null): array
    {
        $perms = $this->perms($e->show->company, $user);
        $finance = in_array('finance.view', $perms, true);
        $conflicts = $this->planning->personConflicts($e);
        $used = [];
        foreach ($e->participations as $p) {
            $used[$p->person->id] = true;
        }
        $dec = fn (string $x) => (float) $x;

        $data = $this->eventSummary($e) + [
            'permissions' => $perms,
            'companyId' => $e->show->company->id, 'companyName' => $e->show->company->name,
            'address' => $e->address, 'venueContact' => $e->venueContact, 'notes' => $e->notes,
            'techArrival' => Input::iso($e->techArrival), 'artisticArrival' => Input::iso($e->artisticArrival),
            'departure' => Input::iso($e->departure), 'performances' => $e->performances,
            'rates' => ['mealRate' => $dec($e->mealRate), 'breakfastRate' => $dec($e->breakfastRate), 'nightRate' => $dec($e->nightRate),
                'truckRate' => $dec($e->truckRate), 'vatRate' => $dec($e->vatRate), 'fee' => $dec($e->fee)],
            'dates' => array_map(fn ($d) => $d->format('Y-m-d'), $e->spanDates()),
            'participations' => $e->participations->map(fn ($p) => [
                'id' => $p->id, 'person' => $this->personFor($p->person, $perms), 'role' => $p->role, 'roomType' => $p->roomType,
                'departureCity' => $p->departureCity, 'lodgingId' => $p->lodging?->id, 'note' => $p->note,
                'days' => array_combine(
                    $p->days->map(fn ($d) => $d->day->format('Y-m-d'))->getValues(),
                    $p->days->map(fn ($d) => ['night' => $d->night, 'meals' => $d->meals, 'breakfast' => $d->breakfast])->getValues(),
                ) ?: new \stdClass(),
                'legs' => array_map(fn ($l) => ['id' => $l->id, 'direction' => $l->direction, 'day' => Input::iso($l->day),
                    'origin' => $l->origin, 'destination' => $l->destination, 'depTime' => $l->depTime, 'arrTime' => $l->arrTime,
                    'mode' => $l->mode, 'reference' => $l->reference, 'price' => $finance ? (float) $l->price : null,
                    'bookedOn' => Input::iso($l->bookedOn), 'note' => $l->note, 'bookingUrl' => $l->bookingUrl], self::byTime($p->legs->toArray(), 'depTime')),
                'conflicts' => array_map(fn (Event $o) => ['id' => $o->id, 'showTitle' => $o->show->title, 'venue' => $o->venue],
                    $conflicts[$p->person->id] ?? []),
            ])->getValues(),
            'availablePeople' => array_values(array_map(fn (Person $p) => $this->personFor($p, $perms),
                array_filter($e->show->company->people->toArray(), fn (Person $p) => !isset($used[$p->id])))),
            'lodgings' => $e->lodgings->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'address' => $l->address,
                'phone' => $l->phone, 'details' => $l->details])->getValues(),
            'trucks' => $e->trucks->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'km' => (float) $t->km])->getValues(),
            'extras' => $e->extras->map(fn ($x) => ['id' => $x->id, 'label' => $x->label, 'amount' => (float) $x->amount,
                'kind' => $x->kind])->getValues(),
            'sections' => $e->sections->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'body' => $s->body,
                'position' => $s->position])->getValues(),
            'schedule' => array_map(fn ($s) => ['id' => $s->id, 'day' => Input::iso($s->day), 'time' => $s->time,
                'label' => $s->label, 'detail' => $s->detail], self::byTime($e->schedule->toArray(), 'time')),
            'overlaps' => array_map(fn (Event $o) => $this->eventSummary($o), $this->planning->overlaps($e)),
            'team' => $this->team($e),
            'readiness' => $this->readinessFor($e, $perms),
            'share' => ['token' => in_array('share.manage', $perms, true) ? $e->shareToken : null, 'sharedAt' => $e->sharedAt?->format(\DATE_ATOM),
                'emails' => in_array('people.view', $perms, true) ? array_values(array_filter($e->participations->map(fn ($p) => $p->person->email)->getValues())) : []],
            'quote' => $this->quotes->compute($e),
            'personCosts' => $this->quotes->personCosts($e),
        ];
        if (!$finance) {
            unset($data['rates'], $data['quote'], $data['personCosts']);
            [$data['trucks'], $data['extras']] = [[], []];
        }

        return $data;
    }

    /** Par jour puis heure réelle (« 9h10 » avant « 11h02 »), l'ordre de saisie départageant les ex æquo. */
    private static function byTime(array $items, string $field): array
    {
        usort($items, fn ($a, $b) => [$a->day, Input::minutes($a->$field)] <=> [$b->day, Input::minutes($b->$field)]);

        return $items;
    }
}
