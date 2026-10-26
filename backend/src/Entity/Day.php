<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Nuitée / repas / petit-déjeuner d'une personne pour un jour donné. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['participation_id', 'day'])]
class Day
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Participation $participation;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $day;

    /** Nuit du jour J au J+1 (0/1) */
    #[ORM\Column(type: Types::SMALLINT)]
    public int $night = 0;

    /** Repas midi + soir (0 à 2) */
    #[ORM\Column(type: Types::SMALLINT)]
    public int $meals = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $breakfast = 0;

    public function __construct(Participation $participation, \DateTimeImmutable $day, int $night = 0, int $meals = 0, int $breakfast = 0)
    {
        $this->participation = $participation;
        $this->day = $day;
        $this->night = $night;
        $this->meals = $meals;
        $this->breakfast = $breakfast;
    }
}
