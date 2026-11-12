<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Participation;

/**
 * Chambres d'une date : une par personne, sauf les chambres partagées (double, twin : deux occupants ; appartement : plusieurs).
 * Sert à la rooming-list (PDF, Excel) et au devis, où la nuitée est comptée par chambre.
 */
class Rooming
{
    /**
     * @return list<array{occupants: list<Participation>, type: string, lodging: string, nights: array<string, bool>, total: int, warning: string}>
     *                                                                                                   nights : AAAA-MM-JJ → chambre occupée cette nuit-là
     */
    public static function rooms(Event $ev): array
    {
        $dates = array_map(fn ($d) => $d->format('Y-m-d'), $ev->spanDates());
        $done = $rooms = [];
        foreach ($ev->participations as $p) {
            if (isset($done[spl_object_id($p)])) {
                continue;
            }
            $occupants = self::occupants($p);
            foreach ($occupants as $o) {
                $done[spl_object_id($o)] = true;
            }
            $nights = [];
            foreach ($dates as $d) {
                $nights[$d] = (bool) array_filter($occupants, fn ($o) => (bool) ($o->day(new \DateTimeImmutable($d))?->night));
            }
            $shared = count($occupants) > 1;
            $warning = match (true) {
                !$shared && in_array($p->roomType, ['double', 'twin'], true) => 'Chambre à partager : second occupant à choisir',
                $shared && count(array_unique(array_map(fn ($o) => $o->lodging?->id ?? 0, $occupants))) > 1 => 'Hébergements différents',
                $shared && count(array_unique(array_map(fn ($o) => implode(',', self::nightsOf($o, $dates)), $occupants))) > 1 => 'Nuits différentes',
                default => '',
            };
            $rooms[] = ['occupants' => $occupants, 'type' => Participation::ROOM_TYPES[$p->roomType] ?? $p->roomType, 'lodging' => $p->lodging?->name ?? '',
                'nights' => $nights, 'total' => count(array_filter($nights)), 'warning' => $warning];
        }

        return $rooms;
    }

    /** Nuitées par chambre sur toute la date (une chambre partagée compte une fois par nuit). */
    public static function roomNights(Event $ev): int
    {
        $n = 0;
        foreach (self::rooms($ev) as $room) {
            $n += $room['total'];
        }

        return $n;
    }

    /** Occupants de la chambre d'une personne (elle comprise), dans l'ordre de l'équipe. */
    public static function occupants(Participation $p): array
    {
        if (null === $p->roomGroup || !array_key_exists($p->roomType, Participation::SHARED_ROOMS)) {
            return [$p];
        }

        return array_values(array_filter($p->event->participations->toArray(), fn ($o) => $o->roomGroup === $p->roomGroup)) ?: [$p];
    }

    private static function nightsOf(Participation $p, array $dates): array
    {
        return array_map(fn ($d) => (int) ($p->day(new \DateTimeImmutable($d))?->night), $dates);
    }
}
