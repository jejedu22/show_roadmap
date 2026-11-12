<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Participation;

/**
 * Chambres d'une date : une par personne, sauf les chambres double / twin partagées (deux occupants, une chambre).
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
            $mate = self::mate($p);
            $occupants = $mate ? [$p, $mate] : [$p];
            foreach ($occupants as $o) {
                $done[spl_object_id($o)] = true;
            }
            $nights = [];
            foreach ($dates as $d) {
                $nights[$d] = (bool) array_filter($occupants, fn ($o) => (bool) ($o->day(new \DateTimeImmutable($d))?->night));
            }
            $warning = match (true) {
                !$mate && in_array($p->roomType, Participation::SHARED_ROOMS, true) => 'Chambre à partager : second occupant à choisir',
                $mate && $p->lodging !== $mate->lodging => 'Hébergements différents',
                $mate && self::nightsOf($p, $dates) !== self::nightsOf($mate, $dates) => 'Nuits différentes',
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
        foreach ($ev->participations as $p) {
            $mate = self::mate($p);
            foreach ($p->days as $d) {
                // Nuit déjà comptée pour l'autre occupant de la chambre
                if ($d->night && !($mate && spl_object_id($mate) < spl_object_id($p) && $mate->day($d->day)?->night)) {
                    $n += $d->night;
                }
            }
        }

        return $n;
    }

    /** Autre occupant d'une chambre partagée, s'il est bien sur la même date et réciproque. */
    public static function mate(Participation $p): ?Participation
    {
        $m = $p->roommate;

        return $m && $m !== $p && $m->roommate === $p && $m->event === $p->event && in_array($p->roomType, Participation::SHARED_ROOMS, true) ? $m : null;
    }

    private static function nightsOf(Participation $p, array $dates): array
    {
        return array_map(fn ($d) => (int) ($p->day(new \DateTimeImmutable($d))?->night), $dates);
    }
}
