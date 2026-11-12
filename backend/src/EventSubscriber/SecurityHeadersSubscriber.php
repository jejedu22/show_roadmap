<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité HTTP sur les réponses de Symfony (API, pages, PDF, liens de partage). En production, Caddy pose les
 * mêmes sur les fichiers du front (docker/Caddyfile) : garder les deux listes identiques.
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** Tout vient du site lui-même ; styles en ligne permis (attributs style de Vue), images data:/blob: (aperçu du logo). */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; "
        ."connect-src 'self'; frame-src 'self'; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; "
        ."form-action 'self'; frame-ancestors 'self'";

    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -100]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        foreach (self::HEADERS as $name => $value) {
            if (!$headers->has($name)) { // un en-tête déjà posé (ex. no-referrer des liens de partage) est gardé
                $headers->set($name, $value);
            }
        }
        // CSP sur les pages HTML seulement : sur un PDF, elle peut gêner la visionneuse du navigateur
        if (str_starts_with((string) $headers->get('Content-Type'), 'text/html') && !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $headers->remove('X-Powered-By');
        if (!headers_sent()) {
            header_remove('X-Powered-By'); // ajouté par PHP lui-même (expose_php)
        }
    }
}
