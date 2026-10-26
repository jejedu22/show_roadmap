<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Journal des actions sensibles (comptes, groupes, connexions). Les libellés sont figés : l'entrée survit à la suppression du compte. */
#[ORM\Entity]
#[ORM\Index(columns: ['at'])]
class AuditLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $at;

    /** Auteur (e-mail au moment des faits) ; vide pour une action anonyme (échec de connexion, lien d'invitation) */
    #[ORM\Column(length: 180)]
    public string $actor = '';

    #[ORM\Column(length: 60)]
    public string $action = '';

    #[ORM\Column(length: 255)]
    public string $target = '';

    #[ORM\Column(type: Types::JSON)]
    public array $details = [];

    #[ORM\Column(length: 45)]
    public string $ip = '';

    public function __construct()
    {
        $this->at = new \DateTimeImmutable();
    }
}
