<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation d'une date, sur le contingent mis à disposition par le lieu : invité de la compagnie (nom, prénom)
 * ou professionnel (nom, prénom, structure), pour une représentation. Jamais dans la feuille de route.
 */
#[ORM\Entity]
class Invitation
{
    public const KINDS = ['company' => 'Cie', 'pro' => 'Pro'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'invitations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    /** Représentation (ligne « Représentation » du planning) ; null = pas encore répartie */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?ScheduleItem $performance = null;

    /** company / pro */
    #[ORM\Column(length: 10)]
    public string $kind = 'company';

    #[ORM\Column(length: 120)]
    public string $lastName = '';

    #[ORM\Column(length: 120)]
    public string $firstName = '';

    /** Structure du professionnel (programmateur, théâtre, festival…) */
    #[ORM\Column(length: 255)]
    public string $structure = '';

    /** Nombre de places de l'invitation */
    #[ORM\Column]
    public int $places = 1;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
