<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TruckLeg
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'trucks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\Column(length: 255)]
    public string $label = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 1)]
    public string $km = '0.0';

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
