<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * - Protection CSRF : toute écriture sur /api doit porter l'en-tête X-Requested-With (impossible à poser
 *   depuis un autre site sans pré-requête CORS, que l'on n'autorise pas). Complète le cookie SameSite=Lax.
 * - Erreurs HTTP de l'API renvoyées en JSON {error}.
 */
class ApiSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 16], // avant le pare-feu (8)
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || $request->isMethodSafe()) {
            return;
        }
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            $event->setResponse(new JsonResponse(['error' => 'Requête refusée (CSRF)'], 403));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        if ($e instanceof HttpExceptionInterface) {
            $event->setResponse(new JsonResponse(['error' => $e->getMessage() ?: 'Erreur'], $e->getStatusCode(), $e->getHeaders()));
        }
    }
}
