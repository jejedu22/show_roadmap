<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Connexion Google : flux « authorization code » ; l'identité est lue via l'endpoint userinfo. */
class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'GOOGLE_CLIENT_ID')] private string $clientId,
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')] private string $clientSecret,
    ) {
    }

    public function enabled(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query(['client_id' => $this->clientId, 'redirect_uri' => $redirectUri,
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account']);
    }

    /** @return array{email: string, emailVerified: bool, name: string} */
    public function fetchIdentity(string $code, string $redirectUri): array
    {
        try {
            $token = $this->http->request('POST', self::TOKEN_URL, ['body' => ['code' => $code, 'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret, 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code']])
                ->toArray()['access_token'] ?? throw new \RuntimeException();
            $info = $this->http->request('GET', self::USERINFO_URL, ['auth_bearer' => $token])->toArray();
        } catch (ExceptionInterface|\RuntimeException) {
            throw new \RuntimeException('Échec de la connexion avec Google');
        }

        return ['email' => mb_strtolower($info['email'] ?? ''), 'emailVerified' => true === ($info['email_verified'] ?? null),
            'name' => $info['name'] ?? ''];
    }
}
