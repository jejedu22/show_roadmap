<?php

namespace App\Service\Orfeo;

use App\Entity\Company;
use App\Service\Import\TourImporter;
use App\Entity\User;
use App\Service\Secrets;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Récupère les dates d'Orfeo et les présente comme des brouillons d'import (même format que les classeurs Excel) :
 * relecture, rapprochement de l'annuaire et des listes, puis enregistrement par TourImporter.
 *
 * Correspondances : spectacle → spectacle, projet → date, lieu → lieu/adresse, participants → équipe (fiche « employee » :
 * nom, ville, téléphone, e-mail), événements de planning → planning sur place (représentations, répétitions, technique,
 * repas, autres) et hébergements. Les voyages Orfeo n'ont ni gares ni billets : ils sont signalés, pas repris.
 */
class OrfeoImporter
{
    public const MAX_PROJECTS = 40;
    private const TYPE_LABELS = ['performance' => 'Représentation', 'rehearsal' => 'Répétition', 'technical' => 'Technique',
        'meal' => 'Repas', 'other' => 'Autre', 'journey' => 'Voyage', 'accommodation' => 'Hébergement'];
    private const TZ = 'Europe/Paris';

    /** @var array<int, array> fiches Orfeo déjà lues pendant cet import */
    private array $employees = [];

    public function __construct(private OrfeoClient $client, private Secrets $secrets, private TourImporter $importer)
    {
    }

    public function token(Company $company): string
    {
        return $this->secrets->decrypt($company->orfeoToken)
            ?? throw new BadRequestHttpException('Compagnie non reliée à Orfeo : saisissez la clé d\'API dans les paramètres de la compagnie.');
    }

    /** Vérifie une clé et renvoie le nom de l'organisation Orfeo correspondante. */
    public function organization(string $token): string
    {
        $profiles = $this->client->all($token, '/api/organizationprofile/');
        $default = array_values(array_filter($profiles, fn ($p) => !empty($p['is_default'])))[0] ?? $profiles[0] ?? [];

        return mb_substr((string) ($default['name'] ?? 'Organisation Orfeo'), 0, 255);
    }

    /** Analyse des dates Orfeo d'une période : renvoie le même résultat que l'analyse des classeurs (relecture). */
    public function analyze(Company $company, User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to < $from) {
            throw new BadRequestHttpException('La fin de la période précède son début.');
        }
        if ($from->diff($to)->days > 400) {
            throw new BadRequestHttpException('Période trop longue : un an au plus.');
        }
        @set_time_limit(300);
        $token = $this->token($company);
        $projects = $this->client->all($token, '/api/project/', [
            'end_date__gt' => $from->modify('-1 day')->format('Y-m-d'),
            'start_date__lt' => $to->modify('+1 day')->format('Y-m-d'),
            'is_archived' => 'false',
            'ordering' => 'start_date',
        ]);
        $truncated = count($projects) > self::MAX_PROJECTS;
        $drafts = [];
        foreach (array_slice($projects, 0, self::MAX_PROJECTS) as $p) {
            try {
                $drafts[] = $this->draft($token, (int) $p['pk']);
            } catch (OrfeoException $e) {
                if (401 === $e->status()) {
                    throw $e;
                }
                $drafts[] = ['file' => 'Orfeo · '.($p['title'] ?? '#'.$p['pk']), 'error' => $e->getMessage()];
            }
        }
        $result = $this->importer->analyzeDrafts($company, $user, $drafts);
        $result['source'] = 'orfeo';
        $result['notice'] = $truncated ? sprintf('%d dates trouvées : seules les %d premières sont présentées. Réduisez la période pour voir les suivantes.',
            count($projects), self::MAX_PROJECTS) : null;

        return $result;
    }

    /** Brouillon d'une date Orfeo (projet). */
    public function draft(string $token, int $projectId): array
    {
        $p = $this->client->get($token, "/api/project/$projectId/");
        $participants = $this->client->all($token, '/api/project_participant/', ['project' => $projectId]);
        $events = $this->client->all($token, '/api/planning_event/', ['project' => $projectId]);
        $contacts = $this->client->all($token, '/api/project_linked_contact/', ['parent' => $projectId]);

        $warnings = [];
        $title = trim((string) ($p['title'] ?? ''));
        $spectacle = is_array($p['spectacle'] ?? null) ? $p['spectacle'] : ['pk' => null, 'name' => (string) ($p['spectacle'] ?? '')];
        $venue = is_array($p['venue'] ?? null) ? $p['venue'] : (is_array($p['place'] ?? null) ? $p['place'] : ['name' => (string) ($p['venue'] ?? '')]);
        $address = trim(implode(', ', array_filter([trim(($venue['address1'] ?? '').' '.($venue['address2'] ?? '')), trim(($venue['zipcode'] ?? '').' '.($venue['city'] ?? ''))])));

        // Équipe : participants retenus (confirmés ou à confirmer), fiche complète lue une fois par personne
        $team = [];
        $byEntity = [];
        foreach ($participants as $part) {
            $status = (string) ($part['status'] ?? 'confirmed');
            $entity = is_array($part['entity'] ?? null) ? $part['entity'] : ['pk' => $part['entity'] ?? null];
            if ('unnamed' === $status) {
                $warnings[] = self::warn('info', 'orfeo_unnamed', sprintf('Poste non pourvu dans Orfeo (« %s ») : non repris.', $part['function'] ?? 'N.N.'));
                continue;
            }
            if (!in_array($status, ['confirmed', 'to-confirm', ''], true) || empty($entity['pk'])) {
                continue;
            }
            $member = $this->member((int) $entity['pk'], $entity, $token);
            $member['key'] = 'p'.count($team);
            $member['role'] = mb_substr(trim((string) ($part['function'] ?? '')) ?: $member['role'], 0, 120);
            $member['note'] = 'to-confirm' === $status ? 'À confirmer dans Orfeo' : mb_substr(trim((string) ($part['notes'] ?? '')), 0, 255);
            $byEntity[(int) $entity['pk']] = $member['key'];
            $team[] = $member;
        }

        // Planning : représentations, répétitions, technique, repas… ; hébergements ; voyages signalés
        $schedule = $lodgings = $perfDays = $allDays = [];
        $journeys = 0;
        foreach ($events as $e) {
            if (!empty($e['is_cancelled'])) {
                continue;
            }
            $start = self::local($e['start_datetime'] ?? null);
            $end = self::local($e['end_datetime'] ?? null);
            if (!$start) {
                continue;
            }
            $allDays[] = $start->format('Y-m-d');
            $end && $allDays[] = $end->format('Y-m-d');
            $type = (string) ($e['activity_type'] ?? 'other');
            $label = trim((string) ($e['title'] ?? '')) ?: self::TYPE_LABELS[$type] ?? 'Autre';
            if ('journey' === $type) {
                ++$journeys;
                continue;
            }
            if ('accommodation' === $type) {
                $place = is_array($e['place'] ?? null) ? $e['place'] : [];
                $lodgings[] = [
                    'name' => mb_substr(trim((string) ($place['name'] ?? '')) ?: $label, 0, 255),
                    'address' => mb_substr(trim(implode(', ', array_filter([trim(($place['address1'] ?? '').' '.($place['address2'] ?? '')), trim(($place['zipcode'] ?? '').' '.($place['city'] ?? ''))]))), 0, 255),
                    'phone' => '',
                    'details' => mb_substr(trim((string) ($e['notes'] ?? '')), 0, 2000),
                    'people' => array_values(array_filter(array_map(fn ($x) => $byEntity[(int) ($x['pk'] ?? 0)] ?? null, (array) ($e['entities'] ?? [])))),
                ];
                continue;
            }
            if ('performance' === $type) {
                $perfDays[] = $start->format('Y-m-d');
            }
            $schedule[] = ['day' => $start->format('Y-m-d'), 'time' => empty($e['is_all_day_event']) ? self::hours($start, $end) : '',
                'label' => mb_substr($label, 0, 255), 'performance' => 'performance' === $type];
        }
        usort($schedule, fn ($a, $b) => [$a['day'], self::minutes($a['time'])] <=> [$b['day'], self::minutes($b['time'])]);
        if ($journeys) {
            $warnings[] = self::warn('info', 'orfeo_journeys', sprintf('%d voyage%s dans le planning Orfeo : horaires sans gares ni billets, à saisir dans l\'onglet Voyages.', $journeys, $journeys > 1 ? 's' : ''));
        }

        // Dates : représentations du planning, sinon celles du projet
        sort($perfDays);
        sort($allDays);
        $first = $perfDays[0] ?? $p['start_date'] ?? $allDays[0] ?? null;
        $last = $perfDays ? end($perfDays) : ($p['end_date'] ?? $first);
        if (!$first) {
            throw new OrfeoException('Date sans jour de début dans Orfeo.', 422);
        }
        $performances = count(array_filter($schedule, fn ($s) => $s['performance'])) ?: count((array) ($p['performances'] ?? [])) ?: 1;
        $arrival = $allDays && $allDays[0] < $first ? $allDays[0] : null;
        $departure = $allDays && end($allDays) > $last ? end($allDays) : null;

        $cancelled = !empty($p['is_cancelled']);
        $statusName = mb_strtolower((string) ($p['status']['name'] ?? ''));
        $status = match (true) {
            $cancelled => 'cancelled',
            str_contains($statusName, 'confirmer') || str_contains($statusName, 'option') || str_contains($statusName, 'pré') => 'option',
            default => 'confirmed',
        };
        if ($cancelled) {
            $warnings[] = self::warn('warning', 'orfeo_cancelled', 'Date annulée dans Orfeo.');
        }
        if (!$team) {
            $warnings[] = self::warn('info', 'orfeo_team', 'Aucun participant confirmé dans Orfeo pour cette date.');
        }

        $contactLines = array_values(array_filter(array_map(fn ($c) => self::contactLine($c), $contacts)));

        return [
            'source' => 'orfeo',
            'file' => 'Orfeo · '.($title ?: ($spectacle['name'] ?? '').' '.$first),
            'fileCity' => '',
            'show' => trim((string) ($spectacle['name'] ?? '')) ?: $title,
            'event' => [
                'venue' => mb_substr(trim((string) ($venue['name'] ?? '')) ?: $title, 0, 255),
                'city' => mb_substr(trim((string) ($venue['city'] ?? '')), 0, 120),
                'address' => mb_substr($address, 0, 255),
                'venueContact' => mb_substr(implode("\n", $contactLines), 0, 2000),
                'firstDate' => $first, 'lastDate' => $last,
                'techArrival' => $arrival, 'artisticArrival' => $arrival, 'departure' => $departure,
                'performances' => $performances, 'status' => $status,
                'fee' => null, 'mealRate' => null, 'breakfastRate' => null, 'truckRate' => null, 'vatRate' => null,
            ],
            'team' => $team, 'fillDays' => true,
            'legs' => [], 'fallbackLegs' => [], 'trucks' => [], 'sections' => self::sections($p),
            'lodgings' => $lodgings,
            'schedule' => array_map(fn ($s) => array_diff_key($s, ['performance' => 1]), $schedule),
            'roadmap' => ['found' => (bool) ($schedule || $lodgings), 'mismatch' => false, 'header' => ''],
            'warnings' => $warnings,
            'orfeo' => ['project' => $projectId, 'spectacle' => $spectacle['pk'] ?? null],
        ];
    }

    /** Fiche d'un participant (employee Orfeo), au format de l'équipe d'un brouillon. */
    private function member(int $pk, array $entity, string $token): array
    {
        if (!array_key_exists($pk, $this->employees)) {
            try {
                $this->employees[$pk] = $this->client->get($token, "/api/employee/$pk/");
            } catch (OrfeoException $e) {
                if (404 !== $e->status()) {
                    throw $e;
                }
                $this->employees[$pk] = []; // contact non salarié : on se contente du nom fourni avec la participation
            }
        }
        $e = $this->employees[$pk] + $entity;
        $first = trim((string) ($e['first_name'] ?? ''));
        $last = trim((string) ($e['last_name'] ?? ''));
        if ('' === $first && '' === $last) {
            [$last, $first] = self::split((string) ($e['name'] ?? $e['artistic_name'] ?? ''));
        }
        $infos = (array) ($e['contact_infos'] ?? []);
        $pick = fn (string $type) => trim((string) (array_values(array_filter($infos, fn ($i) => ($i['type'] ?? '') === $type))[0]['value'] ?? ''));
        $mail = mb_strtolower($pick('mail'));

        return [
            'orfeoId' => $pk, 'lastName' => mb_substr(mb_strtoupper($last), 0, 120), 'firstName' => mb_substr($first, 0, 120),
            'role' => mb_substr(trim((string) ($e['profession'] ?? '')), 0, 120), 'roomType' => 'single',
            'departureCity' => mb_substr(trim((string) ($e['city'] ?? '')), 0, 120),
            'outboundPrice' => 0.0, 'returnPrice' => 0.0, 'note' => '',
            'phone' => mb_substr($pick('phone'), 0, 60), 'email' => filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : '',
            'diet' => '', 'days' => [], 'lodging' => null,
        ];
    }

    /** Notes publiques du projet (planning, régie) reprises en rubriques de la feuille de route. */
    private static function sections(array $p): array
    {
        $out = [];
        foreach (['planning_notes' => 'Planning', 'stage_management_notes' => 'Régie', 'notes' => 'Notes'] as $field => $title) {
            $body = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) ($p[$field] ?? ''))));
            if ('' !== $body) {
                $out[] = ['title' => $title, 'body' => mb_substr(html_entity_decode($body, ENT_QUOTES | ENT_HTML5), 0, 5000)];
            }
        }

        return $out;
    }

    /** « Théâtre X — Marie Test (Accueil) · 06… · marie@… » */
    private static function contactLine(array $c): ?string
    {
        $sp = is_array($c['structure_person'] ?? null) ? $c['structure_person'] : [];
        $person = is_array($sp['person'] ?? null) ? $sp['person'] : [];
        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? ''));
        $infos = array_map(fn ($i) => trim((string) ($i['value'] ?? '')), (array) ($sp['contact_infos'] ?? []));
        $who = trim($name.(($sp['title'] ?? '') ? ' ('.$sp['title'].')' : ''));
        $line = implode(' · ', array_filter([$who, ...$infos]));

        return '' === $line ? null : $line;
    }

    private static function local(?string $iso): ?\DateTimeImmutable
    {
        if (!$iso) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(self::TZ));
        } catch (\Exception) {
            return null;
        }
    }

    /** 9:00–13:00 → « 9h - 13h » ; 20:30 → « 20h30 ». */
    private static function hours(\DateTimeImmutable $start, ?\DateTimeImmutable $end): string
    {
        $h = fn (\DateTimeImmutable $d) => (int) $d->format('G').'h'.('00' === $d->format('i') ? '' : $d->format('i'));

        return $end && $end > $start && $end->format('Y-m-d') === $start->format('Y-m-d') && $end->getTimestamp() - $start->getTimestamp() > 900
            ? $h($start).' - '.$h($end) : $h($start);
    }

    private static function minutes(string $t): int
    {
        return preg_match('/^(\d{1,2})h(\d{0,2})/', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : -1;
    }

    /** « LE GOFF Romain » ou « Romain Le Goff » → [nom, prénom]. */
    private static function split(string $name): array
    {
        $name = trim($name);
        if (preg_match('/^((?:\p{Lu}{2,}[\s-]?)+)\s+(.+)$/u', $name, $m)) {
            return [trim($m[1]), $m[2]];
        }
        $parts = preg_split('/\s+/', $name);

        return count($parts) > 1 ? [array_pop($parts), implode(' ', $parts)] : [$name, ''];
    }

    private static function warn(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }
}
