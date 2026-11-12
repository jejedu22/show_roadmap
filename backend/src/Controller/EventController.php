<?php

namespace App\Controller;

use App\Entity\Day;
use App\Entity\Event;
use App\Entity\ExtraCost;
use App\Entity\Leg;
use App\Entity\Invitation;
use App\Entity\Lodging;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\RoadmapSection;
use App\Entity\ScheduleItem;
use App\Entity\TravelExpense;
use App\Entity\TruckLeg;
use App\Service\Import\Sheet;
use App\Service\Import\TrainSheetParser;
use App\Service\Input;
use App\Service\Invitations;
use App\Service\PdfRenderer;
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
        return $this->json($this->presenter->event($this->event($id), $this->me()));
    }

    #[Route('', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
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
        $ev = $this->event($id, 'events.edit');
        $companyId = $ev->show->company->id;
        $this->em->remove($ev);
        $this->em->flush();

        return $this->json(['companyId' => $companyId]);
    }

    /** Copie la date (équipe et hébergements, sans voyages) pour préparer une nouvelle date. */
    #[Route('/duplicate', methods: ['POST'])]
    public function duplicate(int $id, Planning $planning): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $new = new Event($ev->show, $ev->venue.' (copie)', $ev->firstDate, $ev->lastDate);
        foreach (['city', 'address', 'venueContact', 'status', 'techArrival', 'artisticArrival', 'departure', 'performances', 'notes',
            'mealRate', 'breakfastRate', 'nightRate', 'truckRate', 'vatRate', 'fee', 'transportBudget'] as $f) {
            $new->$f = $ev->$f;
        }
        foreach ($ev->lodgings as $l) {
            $copy = new Lodging($new);
            [$copy->name, $copy->address, $copy->phone, $copy->website, $copy->details] = [$l->name, $l->address, $l->phone, $l->website, $l->details];
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
        $ev = $this->event($id, 'finance.edit');
        $d = $this->body($request);
        foreach (['mealRate', 'breakfastRate', 'nightRate', 'truckRate', 'vatRate', 'fee', 'transportBudget'] as $f) {
            $ev->$f = Input::decimal($d, $f);
        }

        return $this->savedEvent($ev);
    }

    // ---- Équipe ----

    #[Route('/participations', methods: ['POST'])]
    public function addPeople(int $id, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
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
        $ev = $this->event($id, 'events.edit');
        $rows = [];
        foreach ((array) ($this->body($request)['participations'] ?? []) as $row) {
            $rows[(int) ($row['id'] ?? 0)] = (array) $row;
        }
        foreach ($ev->participations as $p) {
            if (!isset($rows[$p->id])) {
                continue;
            }
            $d = $rows[$p->id];
            $p->role = $this->lists->check('function', Input::str($d, 'role', 120), $p->role);
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
        $ev = $this->event($id, 'events.edit');
        $ev->participations->removeElement($this->participation($ev, $pid));

        return $this->savedEvent($ev);
    }

    // ---- Séjour : nuitées / repas / petits-déjeuners ----

    /** Corps : {days: {participationId: {AAAA-MM-JJ: {night, meals, breakfast}}}} */
    #[Route('/days', methods: ['PUT'])]
    public function saveDays(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
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
        $ev = $this->event($id, 'events.edit');
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

    private const KINDS = ['lodging', 'leg', 'expense', 'truck', 'extra', 'section', 'schedule', 'invitation'];

    /** Droit nécessaire pour modifier un type de ligne. */
    private static function itemPerm(string $kind): string
    {
        return match ($kind) {
            'leg', 'expense' => 'travel.edit',
            'truck', 'extra' => 'finance.edit',
            'lodging', 'section', 'schedule', 'invitation' => 'events.edit',
            default => throw new NotFoundHttpException(),
        };
    }

    /** Date dont on modifie une ligne ; une dépense de trajet demande aussi de voir les montants. */
    private function eventForItem(int $id, string $kind): Event
    {
        $ev = $this->event($id, self::itemPerm($kind));
        if ('expense' === $kind) {
            $this->authz->event($this->me(), $ev, 'finance.view');
        }

        return $ev;
    }

    #[Route('/items/{kind}', methods: ['POST'])]
    public function createItem(int $id, string $kind, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->eventForItem($id, $kind);
        $d = $this->body($request);
        $item = match ($kind) {
            'lodging' => new Lodging($ev),
            'leg' => new Leg($this->participation($ev, Input::int($d, 'participationId')),
                Input::date($d, 'day') ?? throw new BadRequestHttpException('Date du trajet obligatoire')),
            'expense' => new TravelExpense($this->participation($ev, Input::int($d, 'participationId')),
                Input::date($d, 'day') ?? throw new BadRequestHttpException('Date de la dépense obligatoire')),
            'truck' => new TruckLeg($ev),
            'extra' => new ExtraCost($ev),
            'section' => new RoadmapSection($ev),
            'schedule' => new ScheduleItem($ev),
            'invitation' => new Invitation($ev),
            default => throw new NotFoundHttpException(),
        };
        if ($item instanceof ScheduleItem) {
            $item->day = Input::date($d, 'day') ?? throw new BadRequestHttpException('Date obligatoire');
        }
        $this->fill($item, $d);
        match (true) {
            $item instanceof Leg => $item->participation->legs->add($item),
            $item instanceof TravelExpense => $item->participation->expenses->add($item),
            $item instanceof Lodging => $ev->lodgings->add($item),
            $item instanceof TruckLeg => $ev->trucks->add($item),
            $item instanceof ExtraCost => $ev->extras->add($item),
            $item instanceof RoadmapSection => $ev->sections->add($item),
            $item instanceof ScheduleItem => $ev->schedule->add($item),
            $item instanceof Invitation => $ev->invitations->add($item),
        };
        $item instanceof Leg && $this->daysFromTravel($planning, $item->participation);

        return $this->savedEvent($ev);
    }

    #[Route('/items/{kind}/{itemId<\d+>}', methods: ['PUT'])]
    public function updateItem(int $id, string $kind, int $itemId, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->eventForItem($id, $kind);
        $item = $this->item($ev, $kind, $itemId);
        $this->fill($item, $this->body($request));
        $item instanceof Leg && $this->daysFromTravel($planning, $item->participation);

        return $this->savedEvent($ev);
    }

    /**
     * Les voyages d'une personne changent : ses nuitées, repas et petits-déjeuners sont recalculés d'après eux
     * (arrivée du dernier trajet aller, départ du premier trajet retour ; voir Planning::fillDefaultDays).
     */
    private function daysFromTravel(Planning $planning, Participation ...$parts): void
    {
        foreach ($parts as $p) {
            $p->days->clear();
        }
        $this->em->flush(); // supprime avant de recréer (contrainte d'unicité participation/jour)
        foreach ($parts as $p) {
            $planning->fillDefaultDays($p);
        }
    }

    #[Route('/items/{kind}/{itemId<\d+>}', methods: ['DELETE'])]
    public function deleteItem(int $id, string $kind, int $itemId, Planning $planning): JsonResponse
    {
        $ev = $this->eventForItem($id, $kind);
        $item = $this->item($ev, $kind, $itemId);
        if ($item instanceof Lodging) {
            foreach ($ev->participations as $p) {
                if ($p->lodging === $item) {
                    $p->lodging = null;
                }
            }
        }
        if ($item instanceof ScheduleItem) {
            // Représentation supprimée : ses invitations restent, à répartir
            foreach ($ev->invitations as $i) {
                if ($i->performance === $item) {
                    $i->performance = null;
                }
            }
        }
        match (true) {
            $item instanceof Leg => $item->participation->legs->removeElement($item),
            $item instanceof TravelExpense => $item->participation->expenses->removeElement($item),
            $item instanceof Lodging => $ev->lodgings->removeElement($item),
            $item instanceof TruckLeg => $ev->trucks->removeElement($item),
            $item instanceof ExtraCost => $ev->extras->removeElement($item),
            $item instanceof RoadmapSection => $ev->sections->removeElement($item),
            $item instanceof ScheduleItem => $ev->schedule->removeElement($item),
            $item instanceof Invitation => $ev->invitations->removeElement($item),
        };
        $item instanceof Leg && $this->daysFromTravel($planning, $item->participation);

        return $this->savedEvent($ev);
    }

    /** Export Excel des seuls coûts (le fichier envoyé par le lien de partage des coûts). */
    #[Route('/costs.xlsx', methods: ['GET'])]
    public function exportCosts(int $id, XlsxExporter $exporter): Response
    {
        $ev = $this->event($id, 'finance.view');

        return self::xlsx($exporter->exportCosts($ev), XlsxExporter::costsFilename($ev));
    }

    /** Active le lien public de l'export des coûts (créé une seule fois, conservé ensuite) et note la date d'envoi. */
    #[Route('/cost-share', methods: ['POST'])]
    public function costShare(int $id): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        $this->authz->event($this->me(), $ev, 'finance.view');
        if (null === $ev->costShareToken) {
            $ev->costShareToken = bin2hex(random_bytes(32));
            $this->audit->log('event.cost_share', sprintf('%s — %s', $ev->show->title, $ev->venue));
        }
        $ev->costSharedAt = new \DateTimeImmutable();

        return $this->savedEvent($ev);
    }

    /** Révoque le lien des coûts : l'ancienne adresse ne fonctionne plus. */
    #[Route('/cost-share', methods: ['DELETE'])]
    public function costUnshare(int $id): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        $this->authz->event($this->me(), $ev, 'finance.view');
        if (null !== $ev->costShareToken) {
            $this->audit->log('event.cost_unshare', sprintf('%s — %s', $ev->show->title, $ev->venue));
        }
        [$ev->costShareToken, $ev->costSharedAt] = [null, null];

        return $this->savedEvent($ev);
    }

    private static function xlsx(string $content, string $name): Response
    {
        return new Response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^\x20-\x7E]/', '', $name) ?: 'export.xlsx'),
        ]);
    }

    #[Route('/export.xlsx', methods: ['GET'])]
    public function export(int $id, XlsxExporter $exporter): Response
    {
        $ev = $this->event($id, 'finance.view');
        $name = str_replace([' ', '/'], ['_', '-'], $ev->show->title.'_'.$ev->venue).'.xlsx';

        return self::xlsx($exporter->export($ev, $this->authz->can($this->me(), 'people.view', $ev->show->company)), $name);
    }

    /** Active le lien public de la feuille de route (créé une seule fois, conservé ensuite) et note la date d'envoi. */
    #[Route('/share', methods: ['POST'])]
    public function share(int $id): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        $ev->shareToken ??= bin2hex(random_bytes(32));
        $ev->sharedAt = new \DateTimeImmutable();

        return $this->savedEvent($ev);
    }

    /** Révoque le lien : l'ancienne adresse ne fonctionne plus. */
    #[Route('/share', methods: ['DELETE'])]
    public function unshare(int $id): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        [$ev->shareToken, $ev->sharedAt] = [null, null];

        return $this->savedEvent($ev);
    }

    /** PDF d'un document ; ?download=1 pour l'enregistrer plutôt que l'afficher. */
    #[Route('/pdf/{doc}', methods: ['GET'])]
    public function pdf(int $id, string $doc, Request $request, PdfRenderer $renderer): Response
    {
        if (!isset(PdfRenderer::DOCS[$doc])) {
            throw new NotFoundHttpException('Document inconnu');
        }
        $ev = $this->event($id, PdfRenderer::PERMS[$doc]);
        $name = PdfRenderer::filename($ev, $doc);
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $name) ?: 'document.pdf';
        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;

        return new Response($renderer->render($ev, $doc, $this->me()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $name, $ascii),
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
            'expense' => array_reduce($ev->participations->toArray(), fn ($found, $p) => $found ?? $find($p->expenses)),
            'lodging' => $find($ev->lodgings),
            'truck' => $find($ev->trucks),
            'extra' => $find($ev->extras),
            'section' => $find($ev->sections),
            'schedule' => $find($ev->schedule),
            'invitation' => $find($ev->invitations),
        };

        return $item ?? throw new NotFoundHttpException('Élément introuvable');
    }

    private function fill(object $item, array $d): void
    {
        match (true) {
            $item instanceof Lodging => [$item->name, $item->address, $item->phone, $item->website, $item->details] =
                [Input::str($d, 'name') ?: throw new BadRequestHttpException('Nom obligatoire'), Input::str($d, 'address'),
                    Input::str($d, 'phone', 60), Input::url($d, 'website'), Input::text($d, 'details')],
            $item instanceof Leg => $this->fillLeg($item, $d, $this->lists->check('mode', Input::str($d, 'mode', 60), $item->mode)),
            $item instanceof TravelExpense => [$item->direction, $item->day, $item->kind, $item->amount, $item->comment] =
                ['return' === ($d['direction'] ?? null) ? 'return' : 'outbound', Input::date($d, 'day') ?? $item->day,
                    $this->lists->check('expense', Input::str($d, 'kind', 120) ?: throw new BadRequestHttpException('Type de dépense obligatoire'), $item->kind),
                    Input::decimal($d, 'amount'), Input::str($d, 'comment')],
            $item instanceof TruckLeg => [$item->label, $item->km] = [Input::str($d, 'label'), Input::decimal($d, 'km', 1)],
            $item instanceof ExtraCost => [$item->label, $item->amount, $item->kind] =
                [Input::str($d, 'label') ?: throw new BadRequestHttpException('Libellé obligatoire'), Input::decimal($d, 'amount'),
                    'team_transport' === ($d['kind'] ?? null) ? 'team_transport' : 'other'],
            $item instanceof RoadmapSection => [$item->title, $item->body, $item->position] =
                [Input::str($d, 'title') ?: throw new BadRequestHttpException('Titre obligatoire'), Input::text($d, 'body'), Input::int($d, 'position')],
            $item instanceof Invitation => $this->fillInvitation($item, $d),
            $item instanceof ScheduleItem => [$item->day, $item->time, $item->label, $item->detail] =
                [Input::date($d, 'day') ?? $item->day, Input::str($d, 'time', 20),
                    $this->lists->check('schedule', Input::str($d, 'label') ?: throw new BadRequestHttpException('Intitulé obligatoire'), $item->label),
                    Input::str($d, 'detail')],
        };
    }

    private function fillInvitation(Invitation $i, array $d): void
    {
        $i->kind = 'pro' === ($d['kind'] ?? null) ? 'pro' : 'company';
        $i->lastName = Input::str($d, 'lastName', 120) ?: throw new BadRequestHttpException('Nom obligatoire');
        $i->firstName = Input::str($d, 'firstName', 120);
        $i->structure = 'pro' === $i->kind ? Input::str($d, 'structure') : '';
        $i->places = max(1, min(50, Input::int($d, 'places', 1)));
        $pid = Input::int($d, 'performanceId');
        $i->performance = $pid ? (array_values(array_filter(Invitations::performances($i->event), fn ($s) => $s->id === $pid))[0]
            ?? throw new BadRequestHttpException('Représentation introuvable')) : null;
    }

    /**
     * Places d'invitation mises à disposition par le lieu : par représentation ({performances: {id: places}}),
     * ou pour toute la date ({total: places}) quand le planning n'a pas de représentation.
     */
    #[Route('/invitation-quotas', methods: ['PUT'])]
    public function invitationQuotas(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $d = $this->body($request);
        $ev->invitationQuota = max(0, min(10000, Input::int($d, 'total')));
        $quotas = is_array($d['performances'] ?? null) ? $d['performances'] : [];
        foreach (Invitations::performances($ev) as $s) {
            if (isset($quotas[$s->id]) && is_scalar($quotas[$s->id])) {
                $s->invitationQuota = max(0, min(10000, (int) $quotas[$s->id]));
            }
        }

        return $this->savedEvent($ev);
    }

    // ---- Import de l'onglet « Horaires TGV » d'un classeur de devis vers les voyages ----

    /** Lecture du classeur : trajets par prénom, rattachés aux participants ; rien n'est enregistré. */
    #[Route('/legs/excel', methods: ['POST'])]
    public function previewLegs(int $id, Request $request, TrainSheetParser $parser): JsonResponse
    {
        $ev = $this->event($id, 'travel.edit');
        $file = $request->files->get('file');
        $file = is_array($file) ? ($file[0] ?? null) : $file;
        if (!$file || !$file->isValid() || $file->getSize() > 10 * 1024 * 1024) {
            throw new BadRequestHttpException('Fichier illisible ou trop volumineux (10 Mo maximum).');
        }
        if (!preg_match('/\.xlsx$/i', (string) $file->getClientOriginalName()) || 'PK' !== file_get_contents($file->getPathname(), false, null, 0, 2)) {
            throw new BadRequestHttpException('Seuls les classeurs Excel .xlsx sont acceptés.');
        }
        try {
            $data = $parser->parse($file->getPathname(), $ev);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        } catch (\Throwable) {
            throw new BadRequestHttpException('Classeur illisible (format inattendu ou fichier abîmé).');
        }
        $participants = [];
        foreach ($ev->participations as $p) {
            $participants[$p->id] = ['first' => $p->person->firstName, 'last' => $p->person->lastName];
        }
        // Trajet déjà saisi pour cette personne (même jour, mêmes gares, même départ) : proposé décoché
        $same = fn (Leg $l, array $x) => $l->direction === $x['direction'] && $l->day->format('Y-m-d') === $x['day']
            && Sheet::norm($l->origin) === Sheet::norm($x['origin']) && Sheet::norm($l->destination) === Sheet::norm($x['destination'])
            && $l->depTime === $x['depTime'];
        foreach ($data['persons'] as &$person) {
            $person['participationId'] = TrainSheetParser::match($person['name'], $participants);
            $part = $person['participationId'] ? $this->participation($ev, $person['participationId']) : null;
            foreach ($person['legs'] as &$leg) {
                $leg['exists'] = $part && $part->legs->exists(fn ($k, Leg $l) => $same($l, $leg));
            }
        }
        unset($person, $leg);

        return $this->json($data + ['participants' => array_map(fn ($pid, $p) => ['id' => $pid, 'name' => trim($p['first'].' '.$p['last'])],
            array_keys($participants), $participants)]);
    }

    /** Enregistre les trajets retenus à l'écran ; « replace » efface d'abord les trajets des personnes concernées. */
    #[Route('/legs/import', methods: ['POST'])]
    public function importLegs(int $id, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->event($id, 'travel.edit');
        $d = $this->body($request);
        $rows = array_values(array_filter((array) ($d['legs'] ?? []), 'is_array'));
        if (!$rows) {
            throw new BadRequestHttpException('Aucun trajet à importer.');
        }
        if (count($rows) > 500) {
            throw new BadRequestHttpException('500 trajets au plus par import.');
        }
        $parts = [];
        foreach ($rows as $row) {
            $parts[(int) ($row['participationId'] ?? 0)] ??= $this->participation($ev, (int) ($row['participationId'] ?? 0));
        }
        $removed = 0;
        if (!empty($d['replace'])) {
            foreach ($parts as $part) {
                $removed += $part->legs->count();
                $part->legs->clear();
            }
        }
        foreach ($rows as $row) {
            $part = $parts[(int) $row['participationId']];
            $leg = new Leg($part, Input::date($row, 'day') ?? throw new BadRequestHttpException('Date du trajet manquante.'));
            $this->fillLeg($leg, $row);
            $part->legs->add($leg);
        }
        $this->daysFromTravel($planning, ...array_values($parts));
        $this->audit->log('event.legs_import', sprintf('%s — %s', $ev->show->title, $ev->venue), [
            'trajets' => count($rows), 'personnes' => count($parts), 'remplacés' => $removed,
        ]);

        return $this->savedEvent($ev);
    }

    /** $mode : mode déjà contrôlé par la liste (saisie) ; à l'import, la valeur du classeur est gardée telle quelle (« hors liste » au besoin). */
    private function fillLeg(Leg $leg, array $d, ?string $mode = null): void
    {
        $leg->direction = 'return' === ($d['direction'] ?? null) ? 'return' : 'outbound';
        $leg->day = Input::date($d, 'day') ?? throw new BadRequestHttpException('Date du trajet obligatoire');
        $leg->origin = Input::str($d, 'origin', 120);
        $leg->destination = Input::str($d, 'destination', 120);
        $leg->depTime = Input::str($d, 'depTime', 20);
        $leg->arrTime = Input::str($d, 'arrTime', 20);
        $leg->mode = $mode ?? Input::str($d, 'mode', 60);
        // Classe : seulement pour un mode qui en a une (sinon effacée, par exemple si le mode change)
        $class = Input::str($d, 'travelClass', 1);
        $leg->travelClass = in_array($class, ['1', '2'], true) && $this->lists->hasClass($leg->mode) ? $class : '';
        $leg->reference = Input::str($d, 'reference', 60);
        $leg->price = Input::decimal($d, 'price');
        $leg->bookedOn = Input::date($d, 'bookedOn');
        $leg->note = Input::str($d, 'note');
        $leg->bookingUrl = Input::url($d, 'bookingUrl');
    }
}
