<?php

namespace App\Controller;

use App\Entity\ListItem;
use App\Service\Lists;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Listes paramétrées : lues par tout utilisateur connecté, modifiées avec le droit « Gérer les listes ». */
class ListController extends ApiController
{
    #[Route('/api/lists', methods: ['GET'])]
    public function all(Lists $lists): JsonResponse
    {
        return $this->json(self::payload($lists));
    }

    /** Listes, plus les modes de transport qui demandent une classe (« modeClass ») et l'adresse des sites (« linkUrls »). */
    private static function payload(Lists $lists): array
    {
        return $lists->all() + ['modeClass' => $lists->classModes(), 'linkUrls' => (object) $lists->linkUrls()];
    }

    #[Route('/api/admin/lists/{kind}', methods: ['PUT'])]
    public function replace(string $kind, Request $request, Lists $lists): JsonResponse
    {
        $this->authz->global($this->me(), 'lists.manage');
        if (!isset(ListItem::KINDS[$kind])) {
            throw new NotFoundHttpException('Liste inconnue');
        }
        $before = $lists->entries($kind);
        $classBefore = $lists->classModes();
        $after = $lists->replace($kind, (array) ($this->body($request)['items'] ?? []));
        $details = ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))];
        if ('mode' === $kind && $classBefore !== $lists->classModes()) {
            $details['avec classe'] = $lists->classModes();
        }
        $this->audit->log('lists.update', ListItem::KINDS[$kind], $details, flush: true);

        return $this->json(self::payload($lists));
    }
}
