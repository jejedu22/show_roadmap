<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ExtraCost
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'extras')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\Column(length: 255)]
    public string $label = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $amount = '0.00';

    /** team_transport / other */
    #[ORM\Column(length: 20)]
    public string $kind = 'other';

    public function __construct(Event $event)
    {
        $this->event = $event;
    }
}
