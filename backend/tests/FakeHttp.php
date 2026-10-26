<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Réponses simulées des API externes (SNCF / Google) ; mémorise les URL appelées. */
class FakeHttp
{
    public array $calls = [];
    public array $googleUser = ['email' => 'gina@test.io', 'email_verified' => true, 'name' => 'Gina'];

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->calls[] = $url;
        $json = fn (array $d) => new MockResponse(json_encode($d), ['response_headers' => ['content-type' => 'application/json']]);

        return match (true) {
            str_contains($url, '/places') => $json(['places' => [['id' => 'stop_area:X', 'name' => 'Paris Gare du Nord', 'embedded_type' => 'stop_area']]]),
            str_contains($url, '/journeys') => $json(['journeys' => [['departure_date_time' => '20260601T080500', 'arrival_date_time' => '20260601T091700',
                'duration' => 4320, 'sections' => [['type' => 'public_transport', 'display_informations' => ['commercial_mode' => 'TER', 'trip_short_name' => '857123']], ['type' => 'waiting']]]]]),
            str_contains($url, 'oauth2.googleapis.com/token') => $json(['access_token' => 'tok']),
            str_contains($url, 'userinfo') => $json($this->googleUser),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }
}
