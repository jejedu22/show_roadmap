<?php

namespace App\Controller;

use App\Service\Themes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Thème de couleurs de l'application, des PDF et des exports Excel : choisi avec le droit « Choisir le thème ». */
class ThemeController extends ApiController
{
    #[Route('/api/admin/themes', methods: ['GET'])]
    public function list(Themes $themes): JsonResponse
    {
        $this->authz->global($this->me(), 'theme.manage');

        return $this->json(self::payload($themes));
    }

    #[Route('/api/admin/theme', methods: ['PUT'])]
    public function choose(Request $request, Themes $themes): JsonResponse
    {
        $this->authz->global($this->me(), 'theme.manage');
        $key = (string) ($this->body($request)['key'] ?? '');
        if (!isset(Themes::THEMES[$key])) {
            throw new BadRequestHttpException('Thème inconnu');
        }
        $before = $themes->currentKey();
        $themes->set($key);
        if ($before !== $key) {
            $this->audit->log('settings.theme', Themes::THEMES[$key]['name'], ['avant' => Themes::THEMES[$before]['name']], flush: true);
        }

        return $this->json(self::payload($themes));
    }

    private static function payload(Themes $themes): array
    {
        return ['current' => $themes->currentKey(), 'themes' => array_map(fn ($k, $t) => ['key' => $k] + $t, array_keys(Themes::THEMES), Themes::THEMES)];
    }
}
