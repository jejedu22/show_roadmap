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
use App\Entity\Show;
use App\Entity\TravelExpense;
use App\Entity\TruckLeg;
use App\Service\Import\Sheet;
use App\Service\Import\SignedQuoteParser;
use App\Service\Import\TechPlanningParser;
use App\Service\Import\TrainSheetParser;
use App\Service\Input;
use App\Service\Invitations;
use App\Service\Lists;
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
        // Changement de spectacle (erreur de saisie) : uniquement vers un spectacle de la même compagnie
        if (isset($d['showId']) && (int) $d['showId'] !== $ev->show->id) {
            $show = $this->em->find(Show::class, (int) $d['showId']);
            if (!$show || $show->company !== $ev->show->company) {
                throw new BadRequestHttpException('Spectacle introuvable dans cette compagnie');
            }
            $ev->show = $show;
        }
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
        $copies = [];
        foreach ($ev->participations as $p) {
            $copy = new Participation($new, $p->person);
            [$copy->role, $copy->roomType, $copy->departureCity] = [$p->role, $p->roomType, $p->departureCity];
            $new->participations->add($copy);
            $planning->fillDefaultDays($copy);
            $copies[spl_object_id($p)] = $copy;
        }
        // Chambres partagées reprises à l'identique (numéros de chambre recalculés à l'enregistrement de la copie)
        $this->em->persist($new);
        $this->em->flush();
        foreach ($ev->participations as $p) {
            if (null !== $p->roomGroup) {
                $first = array_values(array_filter($ev->participations->toArray(), fn ($o) => $o->roomGroup === $p->roomGroup))[0];
                $copies[spl_object_id($p)]->roomGroup = $copies[spl_object_id($first)]->id;
            }
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
            $type = Input::str($d, 'roomType', 40);
            $p->roomType = isset(Participation::ROOM_TYPES[$type]) ? $type : 'single';
            $p->departureCity = Input::str($d, 'departureCity', 120);
            $p->note = Input::str($d, 'note');
            $lid = Input::int($d, 'lodgingId');
            $p->lodging = $ev->lodgings->findFirst(fn ($k, $l) => $l->id === $lid);
        }
        $this->pairRooms($ev, $rows);

        return $this->savedEvent($ev);
    }

    /**
     * Chambres partagées : « roomWith » de chaque ligne (les autres occupants de sa chambre). Les occupants reliés forment une
     * chambre, avec le type de chambre de la première personne qui la choisit ; double et twin : deux personnes au plus.
     * Une chambre qui n'est plus double, twin ou appartement n'est plus partagée.
     */
    private function pairRooms(Event $ev, array $rows): void
    {
        $byId = $parent = [];
        foreach ($ev->participations as $p) {
            [$byId[$p->id], $parent[$p->id]] = [$p, $p->id];
        }
        $find = function (int $x) use (&$parent, &$find): int {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $shared = fn ($p) => array_key_exists($p->roomType, Participation::SHARED_ROOMS);
        // Liens : ceux des lignes envoyées, ou, pour les autres personnes, leur chambre actuelle
        $order = [];
        foreach ($ev->participations as $p) {
            $with = isset($rows[$p->id]) && array_key_exists('roomWith', $rows[$p->id])
                ? array_map('intval', (array) $rows[$p->id]['roomWith'])
                : array_values(array_map(fn ($o) => $o->id, array_filter($ev->participations->toArray(), fn ($o) => $o !== $p && null !== $p->roomGroup && $o->roomGroup === $p->roomGroup)));
            foreach ($with as $oid) {
                if (isset($byId[$oid]) && $oid !== $p->id && $shared($p) && $shared($byId[$oid])) { // une personne en single reste seule
                    $order[] = [$p->id, $oid];
                }
            }
        }
        $size = array_fill_keys(array_keys($byId), 1);
        $type = array_map(fn ($p) => $p->roomType, $byId);
        foreach ($order as [$a, $b]) {
            [$ra, $rb] = [$find($a), $find($b)];
            if (!array_key_exists($type[$ra], Participation::SHARED_ROOMS)) {
                continue;
            }
            $max = Participation::SHARED_ROOMS[$type[$ra]]; // null : appartement, sans limite
            if ($ra === $rb || null !== $max && $size[$ra] + $size[$rb] > $max) {
                continue; // déjà ensemble, ou chambre pleine
            }
            [$parent[$rb], $size[$ra]] = [$ra, $size[$ra] + $size[$rb]];
        }
        foreach ($byId as $id => $p) {
            $root = $find($id);
            if ($size[$root] > 1) {
                [$p->roomGroup, $p->roomType] = [$root, $type[$root]]; // même type pour toute la chambre
            } else {
                $p->roomGroup = null;
            }
        }
    }

    #[Route('/participations/{pid<\d+>}', methods: ['DELETE'])]
    public function removePerson(int $id, int $pid): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $part = $this->participation($ev, $pid);
        $ev->participations->removeElement($part);
        // Chambre partagée : celle des autres occupants reste partagée s'ils sont encore au moins deux
        if (null !== $part->roomGroup) {
            $left = array_filter($ev->participations->toArray(), fn ($p) => $p->roomGroup === $part->roomGroup);
            if (count($left) < 2) {
                array_map(fn ($p) => $p->roomGroup = null, $left);
            }
        }

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

    /** Lecture du planning d'une fiche technique PDF (dernière page) : lignes proposées, à vérifier avant d'enregistrer. */
    #[Route('/schedule/pdf', methods: ['POST'])]
    public function readTechPlanning(int $id, Request $request, TechPlanningParser $parser): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $file = $request->files->get('file');
        $file = is_array($file) ? ($file[0] ?? null) : $file;
        if (!$file || !$file->isValid() || $file->getSize() > 10 * 1024 * 1024) {
            throw new BadRequestHttpException('Fichier illisible ou trop volumineux (10 Mo maximum).');
        }
        if ('%PDF-' !== file_get_contents($file->getPathname(), false, null, 0, 5)) {
            throw new BadRequestHttpException('Seules les fiches techniques au format PDF sont acceptées.');
        }
        try {
            return $this->json($parser->parse($parser->text($file->getPathname()), $ev) + ['file' => (string) $file->getClientOriginalName()]);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /** Enregistre les lignes de planning validées (ajout, ou remplacement du planning existant). */
    #[Route('/schedule/import', methods: ['POST'])]
    public function importTechPlanning(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $d = $this->body($request);
        $rows = is_array($d['rows'] ?? null) ? array_values(array_filter($d['rows'], 'is_array')) : [];
        if (!$rows) {
            throw new BadRequestHttpException('Aucune ligne à importer.');
        }
        if (count($rows) > 200) {
            throw new BadRequestHttpException('200 lignes au plus par import.');
        }
        // Intitulés absents de la liste : ajoutés à la liste des intitulés de planning si demandé (« addLabels »), sinon refusés
        $known = array_map([Lists::class, 'key'], $this->lists->entries('schedule'));
        $added = [];
        foreach ($rows as $i => $r) {
            Input::date($r, 'day') ?? throw new BadRequestHttpException(sprintf('Ligne %d : jour manquant.', $i + 1));
            $label = Input::str($r, 'label', 120) ?: throw new BadRequestHttpException(sprintf('Ligne %d : intitulé à choisir.', $i + 1));
            if (!empty($d['addLabels']) && !in_array(Lists::key($label), $known, true)) {
                $known[] = Lists::key($label);
                $added[] = trim(preg_replace('/\s+/u', ' ', $label));
            }
        }
        $added && $this->lists->append('schedule', $added);
        $items = [];
        foreach ($rows as $i => $r) {
            $item = new ScheduleItem($ev);
            $item->day = Input::date($r, 'day');
            $item->time = Input::str($r, 'time', 20);
            $item->label = $this->lists->check('schedule', Input::str($r, 'label', 120));
            $item->detail = Input::str($r, 'detail');
            $items[] = $item;
        }
        $removed = 0;
        if (!empty($d['replace'])) {
            foreach ($ev->schedule->toArray() as $old) {
                foreach ($ev->invitations as $inv) { // invitations de la représentation remplacée : à répartir
                    $inv->performance === $old && $inv->performance = null;
                }
                $ev->schedule->removeElement($old);
                ++$removed;
            }
        }
        foreach ($items as $item) {
            $ev->schedule->add($item);
        }
        $this->audit->log('event.schedule_import', sprintf('%s — %s', $ev->show->title, $ev->venue), array_filter(['lignes' => count($items), 'remplacées' => $removed, 'intitulés ajoutés à la liste' => implode(', ', $added)]));

        return $this->savedEvent($ev);
    }

    /** Devis initial : droit dédié « Saisir le devis initial », avec « Voir les montants ». */
    private function signedEvent(int $id): Event
    {
        $ev = $this->event($id, 'signed.edit');
        $this->authz->event($this->me(), $ev, 'finance.view');

        return $ev;
    }

    /** Lecture d'un devis PDF signé : renvoie ce qui a été lu, à vérifier avant d'enregistrer (rien n'est enregistré ici). */
    #[Route('/signed-quote/pdf', methods: ['POST'])]
    public function readSignedQuote(int $id, Request $request, SignedQuoteParser $parser): JsonResponse
    {
        $this->signedEvent($id);
        $file = $request->files->get('file');
        $file = is_array($file) ? ($file[0] ?? null) : $file;
        if (!$file || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw new BadRequestHttpException('Fichier illisible ou trop volumineux (5 Mo maximum).');
        }
        if ('%PDF-' !== file_get_contents($file->getPathname(), false, null, 0, 5)) {
            throw new BadRequestHttpException('Seuls les devis au format PDF sont acceptés.');
        }
        try {
            return $this->json($parser->parse($parser->text($file->getPathname())) + ['file' => (string) $file->getClientOriginalName()]);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }

    /**
     * Devis initial (saisi ou repris d'un PDF) : en-tête, représentations, défraiements, lignes de déplacement de l'équipe, décor, totaux.
     * Repas = midis + soirs quand ils sont détaillés ; déplacements de l'équipe = somme des lignes quand il y en a.
     */
    #[Route('/signed-quote', methods: ['PUT'])]
    public function saveSignedQuote(int $id, Request $request): JsonResponse
    {
        $ev = $this->signedEvent($id);
        $d = $this->body($request);
        $blank = fn (array $a, string $k) => '' === trim((string) ($a[$k] ?? ''));
        $count = fn (string $k) => $blank($d, $k) ? null : max(0, min(100000, Input::int($d, $k)));
        $money = fn (array $a, string $k) => $blank($a, $k) ? null : round(max(-10000000, min(10000000, Input::float($a, $k))), 2);

        $lines = [];
        foreach (array_slice(is_array($d['lines'] ?? null) ? $d['lines'] : [], 0, 40) as $l) {
            if (!is_array($l) || ('' === trim((string) ($l['label'] ?? '')) && '' === trim((string) ($l['detail'] ?? '')) && $blank($l, 'amount'))) {
                continue;
            }
            $lines[] = ['label' => Input::str($l, 'label', 120), 'detail' => Input::str($l, 'detail', 255), 'amount' => $money($l, 'amount') ?? 0.0];
        }
        $detail = [
            'show' => Input::str($d, 'show', 255), 'organizer' => Input::str($d, 'organizer', 255), 'period' => Input::str($d, 'period', 120),
            'performances' => $count('performances'), 'cession' => $money($d, 'cession'),
            'lunches' => $count('lunches'), 'dinners' => $count('dinners'), 'allowances' => $money($d, 'allowances'),
            'lines' => $lines, 'decor' => $money($d, 'decor'), 'totalHt' => $money($d, 'totalHt'), 'totalTtc' => $money($d, 'totalTtc'),
        ];

        $ev->signedQuoteNumber = Input::str($d, 'number', 40) ?: null;
        $ev->signedQuoteDate = Input::date($d, 'date');
        $ev->signedOn = Input::date($d, 'signedOn');
        $travel = $lines ? round(array_sum(array_column($lines, 'amount')), 2) : $money($d, 'teamTravel');
        $ev->signedTeamTravel = null === $travel ? null : number_format($travel, 2, '.', '');
        $ev->signedMeals = null !== $detail['lunches'] || null !== $detail['dinners'] ? (int) $detail['lunches'] + (int) $detail['dinners'] : $count('meals');
        $ev->signedNights = $count('nights');
        $ev->signedQuoteDetail = array_filter($detail, fn ($v) => null !== $v && '' !== $v && [] !== $v) ?: null;
        $this->audit->log('event.signed_quote', sprintf('%s — %s', $ev->show->title, $ev->venue), array_filter(['devis' => $ev->signedQuoteNumber]));

        return $this->savedEvent($ev);
    }

    #[Route('/signed-quote', methods: ['DELETE'])]
    public function deleteSignedQuote(int $id): JsonResponse
    {
        $ev = $this->signedEvent($id);
        [$ev->signedQuoteNumber, $ev->signedQuoteDate, $ev->signedOn, $ev->signedTeamTravel, $ev->signedMeals, $ev->signedNights, $ev->signedQuoteDetail] = [null, null, null, null, null, null, null];

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
        if (!Event::linkValid($ev->costShareExpiresOn)) { // nouveau lien, ou lien expiré renvoyé : nouvelle échéance
            $ev->costShareExpiresOn = $ev->defaultShareExpiry();
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
        [$ev->costShareToken, $ev->costSharedAt, $ev->costShareExpiresOn] = [null, null, null];

        return $this->savedEvent($ev);
    }

    /** Change la date d'expiration du lien des coûts (prolonger ou raccourcir). */
    #[Route('/cost-share', methods: ['PUT'])]
    public function costShareExpiry(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        $this->authz->event($this->me(), $ev, 'finance.view');
        if (null === $ev->costShareToken) {
            throw new BadRequestHttpException('Aucun lien des coûts à modifier');
        }
        $ev->costShareExpiresOn = $this->expiry($ev, $this->body($request));

        return $this->savedEvent($ev);
    }

    private function expiry(Event $ev, array $d): \DateTimeImmutable
    {
        $date = Input::date($d, 'expiresOn') ?? throw new BadRequestHttpException('Date d\'expiration manquante');
        if ($date < Event::today()) {
            throw new BadRequestHttpException('La date d\'expiration est déjà passée : révoquez plutôt le lien');
        }
        if ($date > $ev->maxShareExpiry()) {
            throw new BadRequestHttpException(sprintf('Un lien peut rester valable au plus jusqu\'au %s (un an après la fin de la date)', $ev->maxShareExpiry()->format('d/m/Y')));
        }

        return $date;
    }

    private static function xlsx(string $content, string $name): Response
    {
        return new Response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^\x20-\x7E]/', '', $name) ?: 'export.xlsx'),
        ]);
    }

    /** Arrivées et départs de l'équipe (Excel), pour organiser les transferts. */
    #[Route('/transfers.xlsx', methods: ['GET'])]
    public function exportTransfers(int $id, XlsxExporter $exporter): Response
    {
        $ev = $this->event($id);

        return self::xlsx($exporter->exportTransfers($ev, $this->authz->can($this->me(), 'people.view', $ev->show->company)), XlsxExporter::transfersFilename($ev));
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
        if (!Event::linkValid($ev->shareExpiresOn)) { // nouveau lien, ou lien expiré renvoyé : nouvelle échéance
            $ev->shareExpiresOn = $ev->defaultShareExpiry();
        }
        $ev->sharedAt = new \DateTimeImmutable();

        return $this->savedEvent($ev);
    }

    /** Révoque le lien : l'ancienne adresse ne fonctionne plus. */
    #[Route('/share', methods: ['DELETE'])]
    public function unshare(int $id): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        [$ev->shareToken, $ev->sharedAt, $ev->shareExpiresOn] = [null, null, null];

        return $this->savedEvent($ev);
    }

    /** Change la date d'expiration du lien de la feuille de route (prolonger ou raccourcir). */
    #[Route('/share', methods: ['PUT'])]
    public function shareExpiry(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'share.manage');
        if (null === $ev->shareToken) {
            throw new BadRequestHttpException('Aucun lien de partage à modifier');
        }
        $ev->shareExpiresOn = $this->expiry($ev, $this->body($request));

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
        $i->ticket = 'detaxe' === ($d['ticket'] ?? null) ? 'detaxe' : 'invitation';
        $i->lastName = Input::str($d, 'lastName', 120) ?: throw new BadRequestHttpException('Nom obligatoire');
        $i->firstName = Input::str($d, 'firstName', 120);
        $i->structure = 'pro' === $i->kind ? Input::str($d, 'structure') : '';
        $i->places = max(1, min(50, Input::int($d, 'places', 1)));
        $pid = Input::int($d, 'performanceId');
        $i->performance = $pid ? (array_values(array_filter(Invitations::performances($i->event), fn ($s) => $s->id === $pid))[0]
            ?? throw new BadRequestHttpException('Représentation introuvable')) : null;
    }

    /**
     * Places mises à disposition par le lieu, en invitations et en détaxes : par représentation
     * ({performances: {id: {invitations, detaxes}}}, ou {id: places} pour les seules invitations),
     * ou pour toute la date ({total, detaxeTotal}) quand le planning n'a pas de représentation.
     */
    #[Route('/invitation-quotas', methods: ['PUT'])]
    public function invitationQuotas(int $id, Request $request): JsonResponse
    {
        $ev = $this->event($id, 'events.edit');
        $d = $this->body($request);
        $n = fn (mixed $v) => max(0, min(10000, (int) $v));
        $ev->invitationQuota = $n(Input::int($d, 'total'));
        $ev->detaxeQuota = $n(Input::int($d, 'detaxeTotal'));
        $quotas = is_array($d['performances'] ?? null) ? $d['performances'] : [];
        foreach (Invitations::performances($ev) as $s) {
            $q = $quotas[$s->id] ?? null;
            if (is_scalar($q)) {
                $s->invitationQuota = $n($q);
            } elseif (is_array($q)) {
                isset($q['invitations']) && is_scalar($q['invitations']) && $s->invitationQuota = $n($q['invitations']);
                isset($q['detaxes']) && is_scalar($q['detaxes']) && $s->detaxeQuota = $n($q['detaxes']);
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

    /**
     * Trajets réutilisables d'autres dates de la compagnie (dates enchaînées) : pour les personnes présentes dans les deux
     * équipes, le retour d'une date précédente devient l'aller ici, l'aller d'une date suivante devient le retour.
     * Dates les plus proches d'abord, seulement celles qui ont des trajets à proposer.
     */
    #[Route('/leg-sources', methods: ['GET'])]
    public function legSources(int $id): JsonResponse
    {
        $ev = $this->event($id, 'travel.edit');
        $finance = $this->authz->can($this->me(), 'finance.view', $ev->show->company);
        $sources = [];
        foreach ($this->siblings($ev) as $src) {
            $legs = [];
            foreach ($this->reusableLegs($ev, $src) as [$leg, $target, $direction]) {
                $legs[] = ['id' => $leg->id, 'participationId' => $target->id, 'person' => $target->person->fullName(), 'direction' => $direction,
                    'day' => Input::iso($leg->day), 'origin' => $leg->origin, 'destination' => $leg->destination, 'depTime' => $leg->depTime,
                    'arrTime' => $leg->arrTime, 'mode' => $leg->mode, 'travelClass' => $leg->travelClass, 'reference' => $leg->reference,
                    'bookingUrl' => '' !== $leg->bookingUrl, 'price' => $finance ? (float) $leg->price : null, 'already' => $this->hasLeg($target, $leg)];
            }
            if ($legs) {
                $sources[] = ['event' => $this->presenter->eventSummary($src), 'position' => $src->firstDate < $ev->firstDate ? 'before' : 'after', 'legs' => $legs];
            }
        }
        usort($sources, fn ($a, $b) => self::gap($ev, $a['event']) <=> self::gap($ev, $b['event']));

        return $this->json(array_slice($sources, 0, 8));
    }

    /** Copie des trajets choisis (voir legSources) dans cette date, sens inversé ; prix repris seulement si demandé (sinon compté deux fois). */
    #[Route('/copy-legs', methods: ['POST'])]
    public function copyLegs(int $id, Request $request, Planning $planning): JsonResponse
    {
        $ev = $this->event($id, 'travel.edit');
        $d = $this->body($request);
        $ids = array_map('intval', (array) ($d['legIds'] ?? []));
        $withPrice = !empty($d['withPrice']) && $this->authz->can($this->me(), 'finance.view', $ev->show->company);
        $wanted = array_flip($ids);
        $parts = [];
        $copied = 0;
        foreach ($this->siblings($ev) as $src) {
            foreach ($this->reusableLegs($ev, $src) as [$leg, $target, $direction]) {
                if (!isset($wanted[$leg->id]) || $this->hasLeg($target, $leg)) {
                    continue;
                }
                $copy = new Leg($target, $leg->day);
                foreach (['origin', 'destination', 'depTime', 'arrTime', 'mode', 'travelClass', 'reference', 'bookedOn', 'note', 'bookingUrl'] as $f) {
                    $copy->$f = $leg->$f;
                }
                $copy->direction = $direction;
                $copy->price = $withPrice ? $leg->price : '0.00';
                $target->legs->add($copy);
                $parts[$target->id] = $target;
                ++$copied;
                unset($wanted[$leg->id]);
            }
        }
        if (!$copied) {
            throw new BadRequestHttpException('Aucun trajet à reprendre : déjà présents, ou personnes absentes de cette date.');
        }
        $this->daysFromTravel($planning, ...array_values($parts));
        $this->audit->log('event.legs_copy', sprintf('%s — %s', $ev->show->title, $ev->venue), ['trajets' => $copied, 'avec prix' => $withPrice ? 'oui' : 'non']);

        return $this->savedEvent($ev);
    }

    /** @return list<Event> autres dates de la même compagnie */
    private function siblings(Event $ev): array
    {
        $out = [];
        foreach ($ev->show->company->shows as $show) {
            foreach ($show->events as $e) {
                if ($e !== $ev) {
                    $out[] = $e;
                }
            }
        }

        return $out;
    }

    /** @return list<array{Leg, Participation, string}> trajet de $src, participation de $ev de la même personne, sens dans $ev */
    private function reusableLegs(Event $ev, Event $src): array
    {
        $before = $src->firstDate < $ev->firstDate;
        [$from, $to] = $before ? ['return', 'outbound'] : ['outbound', 'return'];
        $out = [];
        foreach ($src->participations as $sp) {
            $target = $ev->participations->findFirst(fn ($k, $p) => $p->person === $sp->person);
            if (!$target) {
                continue;
            }
            $legs = array_filter($sp->legs->toArray(), fn (Leg $l) => $l->direction === $from);
            usort($legs, fn ($a, $b) => [$a->day, Input::minutes($a->depTime)] <=> [$b->day, Input::minutes($b->depTime)]);
            foreach ($legs as $l) {
                $out[] = [$l, $target, $to];
            }
        }

        return $out;
    }

    /** Même trajet déjà saisi pour cette personne (jour, villes, heure de départ). */
    private function hasLeg(Participation $p, Leg $leg): bool
    {
        $norm = fn (string $v) => mb_strtolower(trim($v));

        return $p->legs->exists(fn ($k, Leg $l) => $l->day->format('Y-m-d') === $leg->day->format('Y-m-d') && $norm($l->origin) === $norm($leg->origin)
            && $norm($l->destination) === $norm($leg->destination) && $norm($l->depTime) === $norm($leg->depTime));
    }

    /** Écart en jours entre deux dates (fin de l'une, début de l'autre), pour proposer d'abord les plus proches. */
    private static function gap(Event $ev, array $other): int
    {
        $a = $other['firstDate'] < Input::iso($ev->firstDate) ? [$other['end'], Input::iso($ev->start())] : [Input::iso($ev->end()), $other['start']];

        return abs((int) (new \DateTimeImmutable($a[0]))->diff(new \DateTimeImmutable($a[1]))->format('%r%a'));
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
