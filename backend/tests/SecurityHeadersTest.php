<?php

namespace App\Tests;

/** En-têtes de sécurité HTTP : sur toutes les réponses ; CSP sur les pages HTML seulement (pas sur les PDF). */
class SecurityHeadersTest extends ApiTestCase
{
    public function testSecurityHeaders(): void
    {
        $this->client->request('GET', '/api/config', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $h = $this->client->getResponse()->headers;
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $h->get('Referrer-Policy'));
        self::assertStringContainsString('camera=()', $h->get('Permissions-Policy'));
        self::assertNull($h->get('Strict-Transport-Security'), 'HSTS seulement en HTTPS');
        self::assertNull($h->get('Content-Security-Policy'), 'pas de CSP sur le JSON');

        $this->client->request('GET', '/healthz', server: ['HTTPS' => 'on']);
        self::assertSame('max-age=31536000', $this->client->getResponse()->headers->get('Strict-Transport-Security'));

        // Lien de partage (PDF) : Referrer-Policy propre gardée, pas de CSP
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        $token = $this->call('POST', "/api/events/{$ev['id']}/share")['share']['token'];
        $this->client->request('GET', "/partage/$token");
        $h = $this->client->getResponse()->headers;
        self::assertSame('no-referrer', $h->get('Referrer-Policy'));
        self::assertNull($h->get('Content-Security-Policy'));
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
    }

    public function testCspOnHtmlPages(): void
    {
        $file = __DIR__.'/../public/index.html';
        $created = !is_file($file) && false !== file_put_contents($file, '<!doctype html><title>t</title>');
        try {
            $this->client->request('GET', '/mentions-legales');
            $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringContainsString("frame-ancestors 'self'", $csp);
            self::assertStringContainsString("object-src 'none'", $csp);
        } finally {
            $created && unlink($file);
        }
    }
}
