<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class RoadmapSection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\Column(length: 255)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $body = '';

    #[ORM\Column]
    public int $position = 0;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
