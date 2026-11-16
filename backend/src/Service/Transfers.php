<?php

namespace App\Service;

/**
 * Arrivées et départs de l'équipe, pour organiser les transferts : pour chaque personne, l'arrivée du dernier trajet aller
 * (jour, heure d'arrivée, lieu) et le départ du premier trajet retour (jour, heure de départ, lieu).
 * Travaille sur la date telle que la présente Presenter::event (téléphones masqués sans le droit « Voir les coordonnées »).
 */
final class Transfers
{
    /**
     * @return list<array{name: string, role: string, phone: string, arrival: ?array{day: string, time: string, place: string, transport: string},
     *                    departure: ?array{day: string, time: string, place: string, transport: string}}> dans l'ordre des arrivées
     */
    public static function rows(array $ev): array
    {
        $key = fn (array $l, string $time) => [$l['day'], Input::minutes($l[$time] ?: $l['depTime'])];
        $transport = fn (array $l) => implode(' · ', array_filter([$l['mode'], $l['reference']]));
        $rows = [];
        foreach ($ev['participations'] as $p) {
            $out = array_values(array_filter($p['legs'], fn ($l) => 'outbound' === $l['direction']));
            $back = array_values(array_filter($p['legs'], fn ($l) => 'return' === $l['direction']));
            usort($out, fn ($a, $b) => $key($a, 'arrTime') <=> $key($b, 'arrTime'));
            usort($back, fn ($a, $b) => $key($a, 'depTime') <=> $key($b, 'depTime'));
            $a = $out ? end($out) : null; // dernier trajet aller : l'arrivée sur place (après les correspondances)
            $d = $back[0] ?? null;        // premier trajet retour : le départ du lieu
            $rows[] = [
                'name' => $p['person']['fullName'], 'role' => $p['role'], 'phone' => $p['person']['phone'],
                'arrival' => $a ? ['day' => $a['day'], 'time' => $a['arrTime'], 'place' => $a['destination'], 'transport' => $transport($a)] : null,
                'departure' => $d ? ['day' => $d['day'], 'time' => $d['depTime'], 'place' => $d['origin'], 'transport' => $transport($d)] : null,
            ];
        }
        // Dans l'ordre des arrivées (jour, heure), sans aller en dernier ; puis par nom
        usort($rows, fn ($x, $y) => [null === $x['arrival'], $x['arrival']['day'] ?? '', Input::minutes($x['arrival']['time'] ?? ''), $x['name']]
            <=> [null === $y['arrival'], $y['arrival']['day'] ?? '', Input::minutes($y['arrival']['time'] ?? ''), $y['name']]);

        return $rows;
    }
}
