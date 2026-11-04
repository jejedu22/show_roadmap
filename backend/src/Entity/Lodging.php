<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Lodging
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lodgings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 255)]
    public string $address = '';

    #[ORM\Column(length: 60)]
    public string $phone = '';

    /** Site web de l'hébergement : bouton « Site web » dans la feuille de route */
    #[ORM\Column(length: 1000, options: ['default' => ''])]
    public string $website = '';

    /** Horaires, accès, codes, distances… */
    #[ORM\Column(type: Types::TEXT)]
    public string $details = '';

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
