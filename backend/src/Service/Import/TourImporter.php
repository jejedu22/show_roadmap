<?php

namespace App\Service\Import;

use App\Entity\Company;
use App\Entity\Day;
use App\Entity\Event;
use App\Entity\Leg;
use App\Entity\Lodging;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\RoadmapSection;
use App\Entity\ScheduleItem;
use App\Entity\Show;
use App\Entity\TruckLeg;
use App\Entity\User;
use App\Security\Authz;
use App\Service\Audit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Import en deux temps : analyse des classeurs (brouillons gardés côté serveur, rien en base),
 * puis enregistrement des fichiers retenus avec les corrections saisies à l'écran.
 */
class TourImporter
{
    public const MAX_FILES = 20;
    public const MAX_SIZE = 10 * 1024 * 1024;
    private const TTL = 86400;

    public function __construct(
        private EntityManagerInterface $em,
        private TourWorkbookParser $parser,
        private Authz $authz,
        private Audit $audit,
        #[Autowire('%kernel.project_dir%/var/imports')] private string $dir,
    ) {
    }

    /** @param UploadedFile[] $files */
    public function analyze(Company $company, User $user, array $files): array
    {
        if (!$files) {
            throw new BadRequestHttpException('Aucun fichier reçu.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new BadRequestHttpException(sprintf('%d fichiers au maximum par import.', self::MAX_FILES));
        }
        $drafts = [];
        foreach ($files as $file) {
            $name = mb_substr(basename($file->getClientOriginalName()), 0, 200);
            if (!$file->isValid() || $file->getSize() > self::MAX_SIZE) {
                $drafts[] = ['file' => $name, 'error' => 'Fichier illisible ou trop volumineux (10 Mo maximum).'];
                continue;
            }
            if (!preg_match('/\.xlsx$/i', $name) || 'PK' !== file_get_contents($file->getPathname(), false, null, 0, 2)) {
                $drafts[] = ['file' => $name, 'error' => 'Seuls les classeurs Excel .xlsx sont acceptés.'];
                continue;
            }
            try {
                $drafts[] = $this->parser->parse($file->getPathname(), $name);
            } catch (\InvalidArgumentException $e) {
                $drafts[] = ['file' => $name, 'error' => $e->getMessage()];
            } catch (\Throwable) {
                $drafts[] = ['file' => $name, 'error' => 'Classeur illisible (format inattendu ou fichier abîmé).'];
            }
        }
        $this->crossCheck($company, $drafts);

        $id = bin2hex(random_bytes(12));
        $this->store($id, ['user' => $user->id, 'company' => $company->id, 'drafts' => $drafts]);

        return ['id' => $id, 'files' => array_map(fn ($i) => $this->summary($company, $drafts[$i], $i), array_keys($drafts))];
    }

    /** Doublons dans le lot, dates déjà présentes dans l'application, choix par défaut. */
    private function crossCheck(Company $company, array &$drafts): void
    {
        $groups = [];
        foreach ($drafts as $i => $d) {
            if (!isset($d['error'])) {
                $groups[Sheet::norm($d['event']['venue'].'|'.$d['event']['city'].'|'.$d['event']['firstDate'])][] = $i;
            }
        }
        foreach ($groups as $members) {
            // Dans un groupe de fichiers identiques, on garde d'office le premier dont le nom correspond au contenu.
            $keeper = null;
            foreach ($members as $i) {
                if (!in_array('filename', array_column($drafts[$i]['warnings'], 'code'), true)) {
                    $keeper = $i;
                    break;
                }
            }
            foreach ($members as $i) {
                $d = &$drafts[$i];
                $others = array_map(fn ($j) => $drafts[$j]['file'], array_diff($members, [$i]));
                if ($others) {
                    $d['warnings'][] = ['level' => 'danger', 'code' => 'duplicate', 'message' => sprintf(
                        'Même lieu et mêmes dates que %s.', implode(', ', array_map(fn ($f) => "« $f »", $others)))];
                }
                $existing = $this->existingEvent($company, $d);
                if ($existing) {
                    $d['warnings'][] = ['level' => 'danger', 'code' => 'exists', 'message' => sprintf(
                        'Cette date existe déjà dans l’application (%s, %s).', $existing->venue, $existing->firstDate->format('d/m/Y'))];
                }
                $stale = in_array('filename', array_column($d['warnings'], 'code'), true) && ($others || $existing);
                $d['defaults'] = [
                    'include' => !$existing && (!$others ? true : $i === $keeper),
                    'includeRoadmap' => $d['roadmap']['found'] && !$d['roadmap']['mismatch'] && !$stale,
                ];
                unset($d);
            }
        }
    }

    private function existingEvent(Company $company, array $d): ?Event
    {
        $first = new \DateTimeImmutable($d['event']['firstDate']);
        foreach ($this->em->getRepository(Event::class)->findBy(['firstDate' => $first]) as $ev) {
            if ($ev->show->company === $company && (Sheet::norm($ev->venue) === Sheet::norm($d['event']['venue'])
                || ('' !== $ev->city && Sheet::norm($ev->city) === Sheet::norm($d['event']['city'])))) {
                return $ev;
            }
        }

        return null;
    }

    private function summary(Company $company, array $d, int $index): array
    {
        if (isset($d['error'])) {
            return ['index' => $index, 'file' => $d['file'], 'error' => $d['error']];
        }
        $show = $this->findShow($company, $d['show']);
        $people = $this->peopleIndex($company);
        $team = array_map(fn ($p) => [
            'name' => trim($p['firstName'].' '.$p['lastName']), 'role' => $p['role'],
            'known' => isset($people[self::personKey($p['lastName'], $p['firstName'])]),
        ], $d['team']);

        return [
            'index' => $index,
            'file' => $d['file'],
            'show' => ['title' => $d['show'], 'id' => $show?->id, 'matchTitle' => $show?->title],
            'event' => $d['event'] + ['status' => 'confirmed'],
            'team' => $team,
            'counts' => [
                'team' => count($d['team']),
                'newPeople' => count(array_filter($team, fn ($t) => !$t['known'])),
                'nights' => array_sum(array_map(fn ($p) => array_sum(array_column($p['days'], 'night')), $d['team'])),
                'meals' => array_sum(array_map(fn ($p) => array_sum(array_column($p['days'], 'meals')), $d['team'])),
                'legs' => count($d['legs']),
                'tickets' => round(array_sum(array_column($d['fallbackLegs'], 'price')), 2),
                'lodgings' => count($d['lodgings']),
                'sections' => count($d['sections']),
                'schedule' => count($d['schedule']),
            ],
            'roadmap' => ['found' => $d['roadmap']['found'], 'mismatch' => $d['roadmap']['mismatch'], 'header' => $d['roadmap']['header']],
            'warnings' => $d['warnings'],
            'defaults' => $d['defaults'],
        ];
    }

    /**
     * Enregistre les fichiers cochés. $choices : [index => {include, showId|null, showTitle, venue, city, address,
     * firstDate, lastDate, techArrival, artisticArrival, departure, performances, status, includeRoadmap}].
     */
    public function commit(Company $company, User $user, string $id, array $choices): array
    {
        $batch = $this->load($id);
        if (!$batch || $batch['user'] !== $user->id || $batch['company'] !== $company->id) {
            throw new NotFoundHttpException('Import expiré ou introuvable : déposez à nouveau les fichiers.');
        }
        $can = fn (string $perm) => $this->authz->can($user, $perm, $company);
        $people = $this->peopleIndex($company);
        $created = [];
        $skippedTravel = false;

        $this->em->wrapInTransaction(function () use ($company, $batch, $choices, $can, &$people, &$created, &$skippedTravel) {
            foreach ($choices as $index => $c) {
                $d = $batch['drafts'][(int) $index] ?? null;
                if (!$d || isset($d['error']) || empty($c['include'])) {
                    continue;
                }
                $show = $this->resolveShow($company, $c, $d, $can('shows.edit'));
                $event = $this->createEvent($show, $d, $c, $can('finance.edit'));
                $withRoadmap = !empty($c['includeRoadmap']) && $d['roadmap']['found'];
                $parts = $this->addTeam($event, $d, $people);
                if ($can('travel.edit')) {
                    $this->addLegs($d, $parts, $withRoadmap);
                } else {
                    $skippedTravel = true;
                }
                if ($withRoadmap) {
                    $this->addRoadmap($event, $d, $parts);
                }
                foreach ($d['trucks'] as $t) {
                    $truck = new TruckLeg($event);
                    $truck->label = mb_substr($t['label'], 0, 255);
                    $truck->km = number_format((float) $t['km'], 1, '.', '');
                    $this->em->persist($truck);
                }
                $this->em->flush();
                $created[] = ['id' => $event->id, 'file' => $d['file'], 'venue' => $event->venue, 'city' => $event->city,
                    'firstDate' => $event->firstDate->format('Y-m-d'), 'show' => $show->title, 'team' => count($parts)];
                $this->audit->log('import.event', sprintf('%s — %s', $show->title, $event->venue), [
                    'file' => $d['file'], 'event' => $event->id, 'roadmap' => $withRoadmap,
                ]);
            }
            $this->em->flush();
        });
        $this->remove($id);

        return ['created' => $created, 'skippedTravel' => $skippedTravel];
    }

    private function resolveShow(Company $company, array $c, array $d, bool $canCreate): Show
    {
        if (!empty($c['showId'])) {
            $show = $this->em->find(Show::class, (int) $c['showId']);
            if ($show && $show->company === $company) {
                return $show;
            }
        }
        $title = trim(mb_substr((string) ($c['showTitle'] ?? $d['show']), 0, 255)) ?: 'Spectacle importé';
        if ($show = $this->findShow($company, $title)) {
            return $show;
        }
        if (!$canCreate) {
            throw new BadRequestHttpException(sprintf('« %s » : le spectacle « %s » n’existe pas et vous n’avez pas le droit de créer des spectacles.', $d['file'], $title));
        }
        $show = new Show($company, $title);
        $company->shows->add($show);
        $this->em->persist($show);

        return $show;
    }

    private function createEvent(Show $show, array $d, array $c, bool $finance): Event
    {
        $e = $d['event'];
        $date = function (string $k, bool $required = false) use ($c, $e, $d) {
            $v = array_key_exists($k, $c) ? $c[$k] : $e[$k];
            if (null === $v || '' === $v) {
                return $required ? throw new BadRequestHttpException("« {$d['file']} » : date obligatoire manquante.") : null;
            }
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);

            return $dt ?: throw new BadRequestHttpException("« {$d['file']} » : date invalide ($v).");
        };
        $text = fn (string $k, int $max) => mb_substr(trim((string) ($c[$k] ?? $e[$k] ?? '')), 0, $max);

        $first = $date('firstDate', true);
        $last = $date('lastDate') ?? $first;
        if ($last < $first) {
            throw new BadRequestHttpException("« {$d['file']} » : la dernière date précède la première.");
        }
        $event = new Event($show, $text('venue', 255) ?: 'Lieu à préciser', $first, $last);
        $event->city = $text('city', 120);
        $event->address = $text('address', 255);
        $event->venueContact = mb_substr($e['venueContact'], 0, 2000);
        $event->status = in_array($c['status'] ?? '', Event::STATUSES, true) ? $c['status'] : 'confirmed';
        $event->techArrival = $date('techArrival');
        $event->artisticArrival = $date('artisticArrival');
        $event->departure = $date('departure') ?? $last;
        $event->performances = max(1, min(99, (int) ($c['performances'] ?? $e['performances'])));
        $event->notes = sprintf('Importé du classeur « %s » le %s.', $d['file'], (new \DateTimeImmutable())->format('d/m/Y'));
        if ($finance) {
            $money = fn (?float $v, int $scale = 2) => null === $v ? null : number_format($v, $scale, '.', '');
            $event->fee = $money($e['fee']) ?? $event->fee;
            $event->mealRate = $money($e['mealRate']) ?? $event->mealRate;
            $event->breakfastRate = $money($e['breakfastRate']) ?? $event->breakfastRate;
            $event->truckRate = $money($e['truckRate']) ?? $event->truckRate;
            $event->vatRate = $money($e['vatRate']) ?? $event->vatRate;
        }
        $show->events->add($event);
        $this->em->persist($event);

        return $event;
    }

    /** @return array<string, Participation> participations par clé de brouillon */
    private function addTeam(Event $event, array $d, array &$people): array
    {
        $parts = [];
        foreach ($d['team'] as $p) {
            $key = self::personKey($p['lastName'], $p['firstName']);
            $person = $people[$key] ?? null;
            if (!$person) {
                $person = new Person($event->show->company);
                $person->lastName = mb_substr($p['lastName'], 0, 120);
                $person->firstName = mb_substr($p['firstName'], 0, 120);
                $event->show->company->people->add($person);
                $this->em->persist($person);
                $people[$key] = $person;
            }
            // Les fiches existantes ne sont complétées que là où elles sont vides.
            foreach (['function' => $p['role'], 'phone' => $p['phone'], 'email' => $p['email'], 'diet' => $p['diet'], 'homeCity' => $p['departureCity']] as $f => $v) {
                if ('' === $person->$f && '' !== $v) {
                    $person->$f = mb_substr($v, 0, 'email' === $f || 'diet' === $f ? 255 : ('phone' === $f ? 60 : 120));
                }
            }
            $part = new Participation($event, $person);
            $part->role = mb_substr($p['role'] ?: $person->function, 0, 120);
            $part->roomType = $p['roomType'];
            $part->departureCity = mb_substr($p['departureCity'] ?: $person->homeCity, 0, 120);
            $part->note = mb_substr($p['note'], 0, 255);
            foreach ($p['days'] as $day => $x) {
                $part->days->add(new Day($part, new \DateTimeImmutable($day), $x['night'], $x['meals'], $x['breakfast']));
            }
            $event->participations->add($part);
            $this->em->persist($part);
            $parts[$p['key']] = $part;
        }

        return $parts;
    }

    private function addLegs(array $d, array $parts, bool $withRoadmap): void
    {
        $legs = $withRoadmap ? $d['legs'] : [];
        $covered = [];
        foreach ($legs as $l) {
            $covered[$l['person'].'|'.$l['direction']] = true;
        }
        foreach ($d['fallbackLegs'] as $l) {
            if (!isset($covered[$l['person'].'|'.$l['direction']])) {
                $legs[] = $l;
            }
        }
        foreach ($legs as $l) {
            if (!$part = $parts[$l['person']] ?? null) {
                continue;
            }
            $leg = new Leg($part, new \DateTimeImmutable($l['day']));
            $leg->direction = 'return' === $l['direction'] ? 'return' : 'outbound';
            foreach (['origin', 'destination', 'depTime', 'arrTime', 'mode', 'reference', 'note', 'bookingUrl'] as $f) {
                $leg->$f = $l[$f] ?? '';
            }
            $leg->price = number_format((float) $l['price'], 2, '.', '');
            $part->legs->add($leg);
            $this->em->persist($leg);
        }
    }

    private function addRoadmap(Event $event, array $d, array $parts): void
    {
        foreach ($d['lodgings'] as $l) {
            $lodging = new Lodging($event);
            foreach (['name', 'address', 'phone', 'details'] as $f) {
                $lodging->$f = $l[$f];
            }
            $event->lodgings->add($lodging);
            $this->em->persist($lodging);
            foreach ($l['people'] as $key) {
                if (isset($parts[$key]) && !$parts[$key]->lodging) {
                    $parts[$key]->lodging = $lodging;
                }
            }
        }
        // Une seule adresse : toute l'équipe y loge.
        if (1 === count($event->lodgings)) {
            foreach ($parts as $part) {
                $part->lodging ??= $event->lodgings->first();
            }
        }
        foreach ($d['sections'] as $pos => $s) {
            $section = new RoadmapSection($event);
            $section->title = $s['title'];
            $section->body = $s['body'];
            $section->position = $pos;
            $event->sections->add($section);
            $this->em->persist($section);
        }
        foreach ($d['schedule'] as $i) {
            $item = new ScheduleItem($event);
            $item->day = new \DateTimeImmutable($i['day']);
            $item->time = $i['time'];
            $item->label = $i['label'];
            $event->schedule->add($item);
            $this->em->persist($item);
        }
    }

    private function findShow(Company $company, string $title): ?Show
    {
        $n = Sheet::norm($title);
        foreach ($company->shows as $show) {
            if (Sheet::norm($show->title) === $n) {
                return $show;
            }
        }

        return null;
    }

    /** @return array<string, Person> */
    private function peopleIndex(Company $company): array
    {
        $index = [];
        foreach ($company->people as $p) {
            $index[self::personKey($p->lastName, $p->firstName)] ??= $p;
        }

        return $index;
    }

    private static function personKey(string $last, string $first): string
    {
        return Sheet::norm($last).'|'.Sheet::norm($first);
    }

    // ── Brouillons : un fichier JSON par analyse, lié à l'utilisateur et à la compagnie, purgé au bout d'un jour.

    private function store(string $id, array $batch): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0o770, true);
        }
        foreach (glob($this->dir.'/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - self::TTL) {
                @unlink($old);
            }
        }
        file_put_contents($this->path($id), json_encode($batch, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function load(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path) || filemtime($path) < time() - self::TTL) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    private function remove(string $id): void
    {
        @unlink($this->path($id));
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            throw new NotFoundHttpException('Import introuvable.');
        }

        return $this->dir.'/'.$id.'.json';
    }
}
