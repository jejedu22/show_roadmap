<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Une personne rattachée à une date (équipe de la date). */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['event_id', 'person_id'])]
class Participation
{
    public const ROOM_TYPES = ['single', 'double', 'twin', 'appartement'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'participations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Event $event;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Person $person;

    #[ORM\Column(length: 120)]
    public string $role = '';

    #[ORM\Column(length: 40)]
    public string $roomType = 'single';

    #[ORM\Column(length: 120)]
    public string $departureCity = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Lodging $lodging = null;

    #[ORM\Column(length: 255)]
    public string $note = '';

    /** @var Collection<int, Day> */
    #[ORM\OneToMany(targetEntity: Day::class, mappedBy: 'participation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['day' => 'ASC'])]
    public Collection $days;

    /** @var Collection<int, Leg> */
    #[ORM\OneToMany(targetEntity: Leg::class, mappedBy: 'participation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['day' => 'ASC', 'depTime' => 'ASC'])]
    public Collection $legs;

    public function __construct(Event $event, Person $person)
    {
        $this->event = $event;
        $this->person = $person;
        $this->role = $person->function;
        $this->departureCity = $person->homeCity;
        $this->days = new ArrayCollection();
        $this->legs = new ArrayCollection();
    }

    public function day(\DateTimeInterface $d): ?Day
    {
        foreach ($this->days as $x) {
            if ($x->day->format('Y-m-d') === $d->format('Y-m-d')) {
                return $x;
            }
        }

        return null;
    }
}
