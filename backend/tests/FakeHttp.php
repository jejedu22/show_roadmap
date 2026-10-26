<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Réponses simulées des services externes (horaires SNCF, Google) ; mémorise les URL appelées. */
class FakeHttp
{
    public array $calls = [];
    /** Contenu servi pour le téléchargement des horaires SNCF (archive GTFS) */
    public string $gtfsZip = '';
    public array $googleUser = ['email' => 'gina@test.io', 'email_verified' => true, 'name' => 'Gina'];
    /** API Orfeo simulée : réponses par chemin (« /api/project/12/ » ou « /api/planning_event/?project=12 »), clé acceptée */
    public array $orfeo = [];
    public string $orfeoToken = 'cle-orfeo-valide';

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->calls[] = $url;
        $json = fn (array $d) => new MockResponse(json_encode($d), ['response_headers' => ['content-type' => 'application/json']]);

        return match (true) {
            str_contains($url, 'orfeo.test') => $this->orfeo($url, $options),
            str_contains($url, 'gtfs.test') => new MockResponse($this->gtfsZip),
            str_contains($url, 'oauth2.googleapis.com/token') => $json(['access_token' => 'tok']),
            str_contains($url, 'userinfo') => $json($this->googleUser),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function orfeo(string $url, array $options): MockResponse
    {
        $auth = $options['normalized_headers']['authorization'][0] ?? '';
        if ('Authorization: Token '.$this->orfeoToken !== $auth) {
            return new MockResponse('{"detail":"Invalid token."}', ['http_code' => 401]);
        }
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        $page = $q['page'] ?? null;
        unset($q['page_size'], $q['page'], $q['ordering'], $q['is_archived'], $q['end_date__gt'], $q['start_date__lt']);
        $key = $parts['path'].($q ? '?'.http_build_query($q) : '').($page ? '#'.$page : '');
        if (!array_key_exists($key, $this->orfeo)) {
            return new MockResponse('{"detail":"Not found."}', ['http_code' => 404]);
        }

        return new MockResponse(json_encode($this->orfeo[$key]), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
