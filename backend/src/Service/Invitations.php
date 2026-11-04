<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\ScheduleItem;
use App\Service\Import\Sheet;

/**
 * Contingent d'invitations d'une date : places mises à disposition par le lieu, par représentation (lignes « Représentation »
 * du planning) ou, sans représentation au planning, pour toute la date ; places attribuées aux invités Cie et Pro.
 */
class Invitations
{
    /** @return list<ScheduleItem> représentations du planning, dans l'ordre */
    public static function performances(Event $ev): array
    {
        $items = array_values(array_filter($ev->schedule->toArray(), fn (ScheduleItem $s) => str_contains(Sheet::norm($s->label), 'representation')));
        usort($items, fn ($a, $b) => [$a->day, Input::minutes($a->time)] <=> [$b->day, Input::minutes($b->time)]);

        return $items;
    }

    /**
     * @return array{quota: int, used: int, company: int, pro: int, left: int, byPerformance: array<int|string, array{quota: int, company: int, pro: int, left: int}>}
     *                                                                                    clé « none » : invitations sans représentation
     */
    public static function summary(Event $ev): array
    {
        $perfs = self::performances($ev);
        $by = [];
        foreach ($perfs as $s) {
            $by[$s->id] = ['quota' => $s->invitationQuota, 'company' => 0, 'pro' => 0];
        }
        $by['none'] = ['quota' => $perfs ? 0 : $ev->invitationQuota, 'company' => 0, 'pro' => 0];
        foreach ($ev->invitations as $i) {
            $key = $i->performance && isset($by[$i->performance->id]) ? $i->performance->id : 'none';
            $by[$key]['pro' === $i->kind ? 'pro' : 'company'] += $i->places;
        }
        foreach ($by as &$b) {
            $b['left'] = $b['quota'] - $b['company'] - $b['pro'];
        }
        $sum = fn (string $k) => array_sum(array_column($by, $k));

        return ['quota' => $sum('quota'), 'used' => $sum('company') + $sum('pro'), 'company' => $sum('company'), 'pro' => $sum('pro'),
            'left' => $sum('left'), 'byPerformance' => $by];
    }
}
