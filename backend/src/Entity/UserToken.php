<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lien à usage unique pour définir son mot de passe (invitation) ou le réinitialiser.
 * Seule l'empreinte SHA-256 du jeton est stockée : une fuite de la base ne donne aucun lien utilisable.
 */
#[ORM\Entity]
class UserToken
{
    public const INVITE = 'invite';
    public const RESET = 'reset';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(length: 10)]
    public string $type;

    #[ORM\Column(length: 64, unique: true)]
    public string $hash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(User $user, string $type, string $hash, \DateTimeImmutable $expiresAt)
    {
        [$this->user, $this->type, $this->hash, $this->expiresAt] = [$user, $type, $hash, $expiresAt];
    }

    public function isUsable(): bool
    {
        return null === $this->usedAt && $this->expiresAt > new \DateTimeImmutable() && $this->user->isActive;
    }
}
