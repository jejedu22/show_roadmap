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

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->calls[] = $url;
        $json = fn (array $d) => new MockResponse(json_encode($d), ['response_headers' => ['content-type' => 'application/json']]);

        return match (true) {
            str_contains($url, 'gtfs.test') => new MockResponse($this->gtfsZip),
            str_contains($url, 'oauth2.googleapis.com/token') => $json(['access_token' => 'tok']),
            str_contains($url, 'userinfo') => $json($this->googleUser),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }
}
