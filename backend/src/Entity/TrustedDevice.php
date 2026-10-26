<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil de confiance : session gardée ouverte par le cookie « rester connecté ».
 * Le jeton n'est stocké que haché ; il change à chaque reprise de session (voir TrustedDevices).
 */
#[ORM\Entity]
#[ORM\Table(name: 'trusted_device')]
class TrustedDevice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Identifiant de série du cookie (partie non secrète, unique). */
    #[ORM\Column(length: 88, unique: true)]
    public string $series;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /** Empreinte du jeton actuel (SHA-256), suivie de l'empreinte du mot de passe calculée par Symfony. */
    #[ORM\Column(length: 255)]
    public string $tokenHash;

    /** Empreinte du jeton précédent, acceptée une minute après une rotation (onglets ouverts en même temps). */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $previousHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $lastUsed;

    /** « Chrome sur Windows » : déduit du navigateur, pour reconnaître l'appareil dans la liste. */
    #[ORM\Column(length: 120)]
    public string $label = '';

    #[ORM\Column(length: 45)]
    public string $ip = '';

    public function __construct(User $user, string $series, string $tokenHash)
    {
        $this->user = $user;
        $this->series = $series;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $this->lastUsed = new \DateTimeImmutable();
    }
}
