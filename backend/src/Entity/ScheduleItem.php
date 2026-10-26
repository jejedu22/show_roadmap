<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ScheduleItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'schedule')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $day;

    #[ORM\Column(length: 20)]
    public string $time = '';

    /** Intitulé choisi dans la liste paramétrée (« Représentation »). */
    #[ORM\Column(length: 255)]
    public string $label = '';

    /** Précision libre (« #1 - Place Kléber »). */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $detail = '';

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
