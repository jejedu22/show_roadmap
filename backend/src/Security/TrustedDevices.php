<?php

namespace App\Security;

use App\Entity\TrustedDevice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentToken;
use Symfony\Component\Security\Core\Authentication\RememberMe\PersistentTokenInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenProviderInterface;
use Symfony\Component\Security\Core\Authentication\RememberMe\TokenVerifierInterface;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Stockage des jetons « rester connecté » (un par appareil de confiance).
 *
 * Symfony change le jeton à chaque reprise de session ; seule son empreinte SHA-256 est gardée en base, si bien qu'une
 * copie de la base ne permet pas d'ouvrir de session. La partie après « . » (empreinte du mot de passe calculée par
 * Symfony) reste lisible : un changement de mot de passe invalide ainsi le cookie.
 */
class TrustedDevices implements TokenProviderInterface, TokenVerifierInterface
{
    /** Délai pendant lequel le jeton précédent reste accepté après une rotation. */
    private const GRACE = 60;

    public function __construct(private EntityManagerInterface $em, private RequestStack $requests)
    {
    }

    public function loadTokenBySeries(string $series): PersistentTokenInterface
    {
        $d = $this->find($series) ?? throw new TokenNotFoundException('Appareil inconnu ou révoqué.');

        return new PersistentToken($d->user->getUserIdentifier(), $series, $d->tokenHash, $d->lastUsed);
    }

    public function deleteTokenBySeries(string $series): void
    {
        if ($d = $this->find($series)) {
            $this->em->remove($d);
            $this->em->flush();
        }
    }

    public function updateToken(string $series, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        $d = $this->find($series) ?? throw new TokenNotFoundException('Appareil inconnu ou révoqué.');
        [$d->previousHash, $d->rotatedAt] = [$d->tokenHash, new \DateTimeImmutable()];
        $d->tokenHash = self::hash($tokenValue);
        $d->lastUsed = \DateTimeImmutable::createFromInterface($lastUsed);
        $d->ip = mb_substr((string) $this->requests->getMainRequest()?->getClientIp(), 0, 45);
        $this->em->flush();
    }

    public function createNewToken(PersistentTokenInterface $token): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $token->getUserIdentifier()])
            ?? throw new TokenNotFoundException('Utilisateur inconnu.');
        $d = new TrustedDevice($user, $token->getSeries(), self::hash($token->getTokenValue()));
        $request = $this->requests->getMainRequest();
        $d->label = self::label((string) $request?->headers->get('User-Agent'));
        $d->ip = mb_substr((string) $request?->getClientIp(), 0, 45);
        $this->em->persist($d);
        $this->em->flush();
    }

    /** Le jeton présenté (cookie) correspond à l'empreinte stockée, ou au jeton précédent dans la minute qui suit une rotation. */
    public function verifyToken(PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue): bool
    {
        $hash = self::hash($tokenValue);
        if (hash_equals($token->getTokenValue(), $hash)) {
            return true;
        }
        $d = $this->find($token->getSeries());

        return $d && $d->previousHash && $d->rotatedAt && $d->rotatedAt->getTimestamp() + self::GRACE >= time() && hash_equals($d->previousHash, $hash);
    }

    public function updateExistingToken(PersistentTokenInterface $token, #[\SensitiveParameter] string $tokenValue, \DateTimeInterface $lastUsed): void
    {
        // La rotation est enregistrée par updateToken(), appelé juste après par Symfony.
    }

    /** @return list<TrustedDevice> */
    public function of(User $user): array
    {
        return $this->em->getRepository(TrustedDevice::class)->findBy(['user' => $user], ['lastUsed' => 'DESC']);
    }

    /** Révoque les appareils d'un utilisateur (tous, ou tous sauf la série indiquée) ; renvoie le nombre révoqué. */
    public function revokeAll(User $user, ?string $exceptSeries = null): int
    {
        $n = 0;
        foreach ($this->of($user) as $d) {
            if ($d->series !== $exceptSeries) {
                $this->em->remove($d);
                ++$n;
            }
        }

        return $n;
    }

    /** Série du cookie « rester connecté » de la requête en cours (pour signaler « cet appareil »). */
    public function currentSeries(): ?string
    {
        $cookie = (string) $this->requests->getMainRequest()?->cookies->get('REMEMBERME', '');
        try {
            return '' === $cookie ? null : explode(':', RememberMeDetails::fromRawCookie($cookie)->getValue())[0];
        } catch (\Throwable) {
            return null;
        }
    }

    private function find(string $series): ?TrustedDevice
    {
        return $this->em->getRepository(TrustedDevice::class)->findOneBy(['series' => $series]);
    }

    /** « jeton.empreinte-mot-de-passe » → « sha256(jeton complet).empreinte-mot-de-passe ». */
    private static function hash(string $tokenValue): string
    {
        $i = strpos($tokenValue, '.');

        return hash('sha256', $tokenValue).(false === $i ? '' : substr($tokenValue, $i));
    }

    /** Nom lisible de l'appareil à partir du navigateur : « Firefox sur Mac », « Safari sur iPhone »… */
    public static function label(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $os ? "$browser sur $os" : $browser;
    }
}
