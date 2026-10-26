<?php

namespace App\Controller;

use App\Service\SncfTimetable;
use App\Service\SncfException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Recherche d'horaires SNCF (le jeton reste côté serveur). */
#[Route('/api/events/{id<\d+>}/sncf')]
class SncfController extends ApiController
{
    #[Route('/places', methods: ['GET'])]
    public function places(int $id, Request $request, SncfTimetable $sncf): JsonResponse
    {
        $this->event($id);
        $q = trim((string) $request->query->get('q', ''));

        return $this->call(fn () => mb_strlen($q) >= 2 ? $sncf->places($q) : []);
    }

    /** Paire de gares la mieux reliée entre deux villes saisies. */
    #[Route('/pair', methods: ['GET'])]
    public function pair(int $id, Request $request, SncfTimetable $sncf): JsonResponse
    {
        $this->event($id);

        return $this->call(fn () => $sncf->bestPair(trim((string) $request->query->get('from', '')), trim((string) $request->query->get('to', ''))));
    }

    #[Route('/journeys', methods: ['GET'])]
    public function journeys(int $id, Request $request, SncfTimetable $sncf): JsonResponse
    {
        $this->event($id);
        $q = $request->query;

        return $this->call(fn () => $sncf->journeys((string) $q->get('origin'), (string) $q->get('dest'),
            (string) $q->get('day'), (string) $q->get('time', '')));
    }

    #[Route('/connect', methods: ['GET'])]
    public function connect(int $id, Request $request, SncfTimetable $sncf): RedirectResponse
    {
        $this->event($id);
        $q = $request->query;

        return $this->redirect($sncf->connectUrl((string) $q->get('origin', ''), (string) $q->get('dest', ''), (string) $q->get('day', '')));
    }

    private function call(callable $fn): JsonResponse
    {
        try {
            return $this->json($fn());
        } catch (SncfException $e) {
            return $this->json(['error' => $e->getMessage()], $e->status());
        }
    }
}
