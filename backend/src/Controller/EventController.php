<?php

namespace App\Controller;

use App\Entity\Day;
use App\Entity\Event;
use App\Entity\ExtraCost;
use App\Entity\Leg;
use App\Entity\Lodging;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\RoadmapSection;
use App\Entity\ScheduleItem;
use App\Entity\TruckLeg;
use App\Service\Input;
use App\Service\Planning;
use App\Service\XlsxExporter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events/{id<\d+>}')]
class EventController extends ApiController
{
    #[Route('', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json($this->presenter->event($this->event($id)));
    }

    #[Route('', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $d = $this->body($request);
        $ev->venue = Input::str($d, 'venue') ?: $ev->venue;
        $ev->city = Input::str($d, 'city', 120);
        $ev->address = Input::str($d, 'address');
        $ev->venueContact = Input::text($d, 'venueContact');
        $ev->notes = Input::text($d, 'notes');
        $ev->status = in_array($d['status'] ?? null, Event::STATUSES, true) ? $d['status'] : $ev->status;
        $ev->firstDate = Input::date($d, 'firstDate') ?? $ev->firstDate;
        $ev->lastDate = Input::date($d, 'lastDate') ?? $ev->lastDate;
        $ev->techArrival = Input::date($d, 'techArrival');
        $ev->artisticArrival = Input::date($d, 'artisticArrival');
        $ev->departure = Input::date($d, 'departure');
        $ev->performances = max(1, Input::int($d, 'performances', 1));
        if ($ev->lastDate < $ev->firstDate) {
            throw new BadRequestHttpException('La dernière date précède la première');
        }

        return $this->savedEvent($ev);
    }

    #[Route('', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $ev = $this->event($id);
        $companyId = $ev->show->company->id;
        $this->em->remove($ev);
        $this->em->flush();

        return $this->json(['companyId' => $companyId]);
    }

    /** Copie la date (équipe et hébergements, sans voyages) pour préparer une nouvelle date. */
    #[Route('/duplicate', methods: ['POST'])]
    public function duplicate(int $id, Planning $planning): JsonResponse
    {
        $ev = $this->event($id);
        $new = new Event($ev->show, $ev->venue.' (copie)', $ev->firstDate, $ev->lastDate);
        foreach (['city', 'address', 'venueContact', 'status', 'techArrival', 'artisticArrival', 'departure', 'performances', 'notes',
            'mealRate', 'breakfastRate', 'nightRate', 'truckRate', 'vatRate', 'fee'] as $f) {
            $new->$f = $ev->$f;
        }
        foreach ($ev->lodgings as $l) {
            $copy = new Lodging($new);
            [$copy->name, $copy->address, $copy->phone, $copy->details] = [$l->name, $l->address, $l->phone, $l->details];
            $new->lodgings->add($copy);
        }
        foreach ($ev->participations as $p) {
            $copy = new Participation($new, $p->person);
            [$copy->role, $copy->roomType, $copy->departureCity] = [$p->role, $p->roomType, $p->departureCity];
            $new->participations->add($copy);
            $planning->fillDefaultDays($copy);
        }
        $this->em->persist($new);

        return $this->savedEvent($new);
    }

    #[Route('/rates', methods: ['PUT'])]
    public function rates(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $d = $this->body($request);
        foreach (['mealRate', 'breakfastRate', 'nightRate', 'truckRate', 'vatRate', 'fee'] as $f) {
            $ev->$f = Input::decimal($d, $f);
        }

        return $this->savedEvent($ev);
    }

    // ---- Équipe ----

    #[Route('/participations', methods: ['POST'])]
    public function addPeople(int $id, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->event($id);
        $existing = $ev->participations->map(fn ($p) => $p->person->id)->toArray();
        foreach ((array) ($this->body($request)['personIds'] ?? []) as $pid) {
            $person = $this->em->find(Person::class, (int) $pid);
            if (!$person || $person->company !== $ev->show->company || in_array($person->id, $existing, true)) {
                continue;
            }
            $part = new Participation($ev, $person);
            $ev->participations->add($part);
            $planning->fillDefaultDays($part);
            $existing[] = $person->id;
        }

        return $this->savedEvent($ev);
    }

    #[Route('/participations', methods: ['PUT'])]
    public function updateTeam(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $rows = [];
        foreach ((array) ($this->body($request)['participations'] ?? []) as $row) {
            $rows[(int) ($row['id'] ?? 0)] = (array) $row;
        }
        foreach ($ev->participations as $p) {
            if (!isset($rows[$p->id])) {
                continue;
            }
            $d = $rows[$p->id];
            $p->role = Input::str($d, 'role', 120);
            $p->roomType = Input::str($d, 'roomType', 40) ?: 'single';
            $p->departureCity = Input::str($d, 'departureCity', 120);
            $p->note = Input::str($d, 'note');
            $lid = Input::int($d, 'lodgingId');
            $p->lodging = $ev->lodgings->findFirst(fn ($k, $l) => $l->id === $lid);
        }

        return $this->savedEvent($ev);
    }

    #[Route('/participations/{pid<\d+>}', methods: ['DELETE'])]
    public function removePerson(int $id, int $pid): JsonResponse
    {
        $ev = $this->event($id);
        $ev->participations->removeElement($this->participation($ev, $pid));

        return $this->savedEvent($ev);
    }

    // ---- Séjour : nuitées / repas / petits-déjeuners ----

    /** Corps : {days: {participationId: {AAAA-MM-JJ: {night, meals, breakfast}}}} */
    #[Route('/days', methods: ['PUT'])]
    public function saveDays(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $grid = (array) ($this->body($request)['days'] ?? []);
        $dates = $ev->spanDates();
        foreach ($ev->participations as $p) {
            $values = (array) ($grid[$p->id] ?? []);
            foreach ($dates as $date) {
                if (!isset($values[$date->format('Y-m-d')])) {
                    continue;
                }
                $v = (array) $values[$date->format('Y-m-d')];
                $night = max(0, min(1, Input::int($v, 'night')));
                $meals = max(0, min(2, Input::int($v, 'meals')));
                $bf = max(0, min(1, Input::int($v, 'breakfast')));
                if ($row = $p->day($date)) {
                    [$row->night, $row->meals, $row->breakfast] = [$night, $meals, $bf];
                } elseif ($night || $meals || $bf) {
                    $p->days->add(new Day($p, $date, $night, $meals, $bf));
                }
            }
        }

        return $this->savedEvent($ev);
    }

    #[Route('/days/reset', methods: ['POST'])]
    public function resetDays(int $id, Planning $planning): JsonResponse
    {
        $ev = $this->event($id);
        foreach ($ev->participations as $p) {
            $p->days->clear();
        }
        $this->em->flush(); // supprime avant de recréer (contrainte d'unicité participation/jour)
        foreach ($ev->participations as $p) {
            $planning->fillDefaultDays($p);
        }

        return $this->savedEvent($ev);
    }

    // ---- Lignes : hébergements, trajets, camion, frais, feuille de route ----

    private const KINDS = ['lodging', 'leg', 'truck', 'extra', 'section', 'schedule'];

    #[Route('/items/{kind}', methods: ['POST'])]
    public function createItem(int $id, string $kind, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $d = $this->body($request);
        $item = match ($kind) {
            'lodging' => new Lodging($ev),
            'leg' => new Leg($this->participation($ev, Input::int($d, 'participationId')),
                Input::date($d, 'day') ?? throw new BadRequestHttpException('Date du trajet obligatoire')),
            'truck' => new TruckLeg($ev),
            'extra' => new ExtraCost($ev),
            'section' => new RoadmapSection($ev),
            'schedule' => new ScheduleItem($ev),
            default => throw new NotFoundHttpException(),
        };
        if ($item instanceof ScheduleItem) {
            $item->day = Input::date($d, 'day') ?? throw new BadRequestHttpException('Date obligatoire');
        }
        $this->fill($item, $d);
        match (true) {
            $item instanceof Leg => $item->participation->legs->add($item),
            $item instanceof Lodging => $ev->lodgings->add($item),
            $item instanceof TruckLeg => $ev->trucks->add($item),
            $item instanceof ExtraCost => $ev->extras->add($item),
            $item instanceof RoadmapSection => $ev->sections->add($item),
            $item instanceof ScheduleItem => $ev->schedule->add($item),
        };

        return $this->savedEvent($ev);
    }

    #[Route('/items/{kind}/{itemId<\d+>}', methods: ['PUT'])]
    public function updateItem(int $id, string $kind, int $itemId, Request $request): JsonResponse
    {
        $ev = $this->event($id);
        $this->fill($this->item($ev, $kind, $itemId), $this->body($request));

        return $this->savedEvent($ev);
    }

    #[Route('/items/{kind}/{itemId<\d+>}', methods: ['DELETE'])]
    public function deleteItem(int $id, string $kind, int $itemId): JsonResponse
    {
        $ev = $this->event($id);
        $item = $this->item($ev, $kind, $itemId);
        if ($item instanceof Lodging) {
            foreach ($ev->participations as $p) {
                if ($p->lodging === $item) {
                    $p->lodging = null;
                }
            }
        }
        match (true) {
            $item instanceof Leg => $item->participation->legs->removeElement($item),
            $item instanceof Lodging => $ev->lodgings->removeElement($item),
            $item instanceof TruckLeg => $ev->trucks->removeElement($item),
            $item instanceof ExtraCost => $ev->extras->removeElement($item),
            $item instanceof RoadmapSection => $ev->sections->removeElement($item),
            $item instanceof ScheduleItem => $ev->schedule->removeElement($item),
        };

        return $this->savedEvent($ev);
    }

    #[Route('/export.xlsx', methods: ['GET'])]
    public function export(int $id, XlsxExporter $exporter): Response
    {
        $ev = $this->event($id);
        $name = str_replace([' ', '/'], ['_', '-'], $ev->show->title.'_'.$ev->venue).'.xlsx';
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $name) ?: 'export.xlsx';

        return new Response($exporter->export($ev), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $ascii),
        ]);
    }

    private function participation(Event $ev, int $pid): Participation
    {
        return $ev->participations->findFirst(fn ($k, $p) => $p->id === $pid) ?? throw new NotFoundHttpException('Participant introuvable');
    }

    private function item(Event $ev, string $kind, int $itemId): object
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new NotFoundHttpException();
        }
        $find = fn ($collection) => $collection->findFirst(fn ($k, $x) => $x->id === $itemId);
        $item = match ($kind) {
            'leg' => array_reduce($ev->participations->toArray(), fn ($found, $p) => $found ?? $find($p->legs)),
            'lodging' => $find($ev->lodgings),
            'truck' => $find($ev->trucks),
            'extra' => $find($ev->extras),
            'section' => $find($ev->sections),
            'schedule' => $find($ev->schedule),
        };

        return $item ?? throw new NotFoundHttpException('Élément introuvable');
    }

    private function fill(object $item, array $d): void
    {
        match (true) {
            $item instanceof Lodging => [$item->name, $item->address, $item->phone, $item->details] =
                [Input::str($d, 'name') ?: throw new BadRequestHttpException('Nom obligatoire'), Input::str($d, 'address'),
                    Input::str($d, 'phone', 60), Input::text($d, 'details')],
            $item instanceof Leg => $this->fillLeg($item, $d),
            $item instanceof TruckLeg => [$item->label, $item->km] = [Input::str($d, 'label'), Input::decimal($d, 'km', 1)],
            $item instanceof ExtraCost => [$item->label, $item->amount, $item->kind] =
                [Input::str($d, 'label') ?: throw new BadRequestHttpException('Libellé obligatoire'), Input::decimal($d, 'amount'),
                    'team_transport' === ($d['kind'] ?? null) ? 'team_transport' : 'other'],
            $item instanceof RoadmapSection => [$item->title, $item->body, $item->position] =
                [Input::str($d, 'title') ?: throw new BadRequestHttpException('Titre obligatoire'), Input::text($d, 'body'), Input::int($d, 'position')],
            $item instanceof ScheduleItem => [$item->day, $item->time, $item->label] =
                [Input::date($d, 'day') ?? $item->day, Input::str($d, 'time', 20),
                    Input::str($d, 'label') ?: throw new BadRequestHttpException('Intitulé obligatoire')],
        };
    }

    private function fillLeg(Leg $leg, array $d): void
    {
        $leg->direction = 'return' === ($d['direction'] ?? null) ? 'return' : 'outbound';
        $leg->day = Input::date($d, 'day') ?? throw new BadRequestHttpException('Date du trajet obligatoire');
        $leg->origin = Input::str($d, 'origin', 120);
        $leg->destination = Input::str($d, 'destination', 120);
        $leg->depTime = Input::str($d, 'depTime', 20);
        $leg->arrTime = Input::str($d, 'arrTime', 20);
        $leg->mode = Input::str($d, 'mode', 60);
        $leg->reference = Input::str($d, 'reference', 60);
        $leg->price = Input::decimal($d, 'price');
        $leg->bookedOn = Input::date($d, 'bookedOn');
        $leg->note = Input::str($d, 'note');
    }
}
