<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Un trajet (billet). Prix négatif = échange / remboursement. */
#[ORM\Entity]
class Leg
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'legs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Participation $participation;

    /** outbound / return */
    #[ORM\Column(length: 10)]
    public string $direction = 'outbound';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $day;

    #[ORM\Column(length: 120)]
    public string $origin = '';

    #[ORM\Column(length: 120)]
    public string $destination = '';

    #[ORM\Column(length: 20)]
    public string $depTime = '';

    #[ORM\Column(length: 20)]
    public string $arrTime = '';

    #[ORM\Column(length: 60)]
    public string $mode = 'Train';

    #[ORM\Column(length: 60)]
    public string $reference = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $price = '0.00';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $bookedOn = null;

    #[ORM\Column(length: 255)]
    public string $note = '';

    /** Lien vers la réservation ou le billet (SNCF Connect, compagnie aérienne, PDF partagé…) */
    #[ORM\Column(length: 1000)]
    public string $bookingUrl = '';

    public function __construct(Participation $participation, \DateTimeImmutable $day)
    {
        $this->participation = $participation;
        $this->day = $day;
    }
}
