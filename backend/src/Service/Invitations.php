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
     * Invitations (quota, Cie, Pro, restantes) et détaxes (detaxeQuota, detaxe, detaxeLeft), chacune sur son contingent,
     * par représentation et au total.
     *
     * @return array{quota: int, used: int, company: int, pro: int, left: int, detaxeQuota: int, detaxe: int, detaxeLeft: int,
     *               byPerformance: array<int|string, array{quota: int, company: int, pro: int, left: int, detaxeQuota: int, detaxe: int, detaxeLeft: int}>}
     *               clé « none » : invitations et détaxes sans représentation
     */
    public static function summary(Event $ev): array
    {
        $perfs = self::performances($ev);
        $by = [];
        foreach ($perfs as $s) {
            $by[$s->id] = ['quota' => $s->invitationQuota, 'company' => 0, 'pro' => 0, 'detaxeQuota' => $s->detaxeQuota, 'detaxe' => 0];
        }
        $by['none'] = ['quota' => $perfs ? 0 : $ev->invitationQuota, 'company' => 0, 'pro' => 0, 'detaxeQuota' => $perfs ? 0 : $ev->detaxeQuota, 'detaxe' => 0];
        foreach ($ev->invitations as $i) {
            $key = $i->performance && isset($by[$i->performance->id]) ? $i->performance->id : 'none';
            'detaxe' === $i->ticket ? $by[$key]['detaxe'] += $i->places : $by[$key]['pro' === $i->kind ? 'pro' : 'company'] += $i->places;
        }
        foreach ($by as &$b) {
            $b['left'] = $b['quota'] - $b['company'] - $b['pro'];
            $b['detaxeLeft'] = $b['detaxeQuota'] - $b['detaxe'];
        }
        unset($b);
        $sum = fn (string $k) => array_sum(array_column($by, $k));

        return ['quota' => $sum('quota'), 'used' => $sum('company') + $sum('pro'), 'company' => $sum('company'), 'pro' => $sum('pro'),
            'left' => $sum('left'), 'detaxeQuota' => $sum('detaxeQuota'), 'detaxe' => $sum('detaxe'), 'detaxeLeft' => $sum('detaxeLeft'), 'byPerformance' => $by];
    }
}
