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

    /** Horaires, accès, codes, distances… */
    #[ORM\Column(type: Types::TEXT)]
    public string $details = '';

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
