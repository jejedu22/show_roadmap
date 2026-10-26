<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserToken;
use Doctrine\ORM\EntityManagerInterface;

/** Liens d'invitation (7 jours) et de réinitialisation (24 h), à usage unique. */
class UserTokens
{
    private const VALIDITY = [UserToken::INVITE => '+7 days', UserToken::RESET => '+24 hours'];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Crée un lien et invalide les précédents du même type ; renvoie le jeton en clair (affiché une seule fois). */
    public function issue(User $user, string $type): string
    {
        foreach ($this->em->getRepository(UserToken::class)->findBy(['user' => $user, 'type' => $type, 'usedAt' => null]) as $old) {
            $old->usedAt = new \DateTimeImmutable();
        }
        $token = bin2hex(random_bytes(32));
        $this->em->persist(new UserToken($user, $type, hash('sha256', $token), new \DateTimeImmutable(self::VALIDITY[$type])));

        return $token;
    }

    public function find(string $token): ?UserToken
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $t = $this->em->getRepository(UserToken::class)->findOneBy(['hash' => hash('sha256', $token)]);

        return $t?->isUsable() ? $t : null;
    }
}
