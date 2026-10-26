<?php

namespace App\Service\Orfeo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lecture de l'API Orfeo (https://orfeoapp.com/api/doc/) : jeton dans l'en-tête « Authorization: Token … »,
 * résultats paginés (count / next / results), 10 requêtes par seconde au plus par compte.
 */
class OrfeoClient
{
    private const MIN_INTERVAL_US = 110_000; // un peu plus de 0,1 s entre deux appels
    private const MAX_PAGES = 50;

    private float $last = 0.0;
    private int $calls = 0;

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'ORFEO_URL')] private string $baseUrl,
    ) {
    }

    /** Appels effectués (statistique d'import). */
    public function calls(): int
    {
        return $this->calls;
    }

    /** Un objet : /api/project/12/ */
    public function get(string $token, string $path, array $query = []): array
    {
        return $this->request($token, $this->url($path, $query));
    }

    /** Tous les éléments d'une liste, pages suivantes comprises. */
    public function all(string $token, string $path, array $query = []): array
    {
        $items = [];
        $url = $this->url($path, $query + ['page_size' => 100]);
        for ($page = 0; $url && $page < self::MAX_PAGES; ++$page) {
            $data = $this->request($token, $url);
            if (array_is_list($data)) { // liste non paginée
                return [...$items, ...$data];
            }
            $items = [...$items, ...($data['results'] ?? [])];
            $url = $data['next'] ?? null;
            if ($url && !str_starts_with($url, rtrim($this->baseUrl, '/').'/')) {
                throw new OrfeoException('Réponse Orfeo inattendue (page suivante hors du site Orfeo).');
            }
        }

        return $items;
    }

    private function url(string $path, array $query): string
    {
        $query = array_filter($query, fn ($v) => null !== $v && '' !== $v);

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/').($query ? '?'.http_build_query($query) : '');
    }

    private function request(string $token, string $url, bool $retry = true): array
    {
        $wait = self::MIN_INTERVAL_US - (int) ((microtime(true) - $this->last) * 1_000_000);
        if ($wait > 0) {
            usleep($wait);
        }
        $this->last = microtime(true);
        ++$this->calls;
        try {
            $response = $this->http->request('GET', $url, ['headers' => ['Authorization' => 'Token '.$token, 'Accept' => 'application/json'], 'timeout' => 20]);
            $status = $response->getStatusCode();
            if (429 === $status && $retry) {
                usleep(1_000_000);

                return $this->request($token, $url, false);
            }
            if (401 === $status || 403 === $status) {
                throw new OrfeoException('Clé d\'API Orfeo refusée : vérifiez-la dans Orfeo (Paramètres → API & webhooks) et dans les paramètres de la compagnie.', 401);
            }
            if (404 === $status) {
                throw new OrfeoException('Élément introuvable dans Orfeo.', 404);
            }
            if ($status >= 400) {
                throw new OrfeoException("Orfeo a répondu par une erreur ($status). Réessayez dans quelques minutes.", 502);
            }
            $data = json_decode($response->getContent(false), true);
            if (!is_array($data)) {
                throw new OrfeoException('Réponse Orfeo illisible.', 502);
            }

            return $data;
        } catch (TransportExceptionInterface) {
            throw new OrfeoException('Orfeo est injoignable pour le moment. Réessayez dans quelques minutes.', 502);
        }
    }
}
