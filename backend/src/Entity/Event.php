<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une date de tournée : un spectacle dans un lieu pour une période. */
#[ORM\Entity]
#[ORM\Index(columns: ['first_date'])]
class Event
{
    public const STATUSES = ['option', 'confirmed', 'cancelled'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Show $show;

    #[ORM\Column(length: 255)]
    public string $venue = '';

    #[ORM\Column(length: 120)]
    public string $city = '';

    #[ORM\Column(length: 255)]
    public string $address = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $venueContact = '';

    #[ORM\Column(length: 20)]
    public string $status = 'confirmed';

    /** 1re et dernière représentation */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $firstDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $lastDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $techArrival = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $artisticArrival = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $departure = null;

    #[ORM\Column]
    public int $performances = 1;

    #[ORM\Column(type: Types::TEXT)]
    public string $notes = '';

    /** Identifiant de l'objet correspondant dans Orfeo (import), pour ne pas le recréer. */
    #[ORM\Column(nullable: true)]
    public ?int $orfeoId = null;

    /** Lien public (non devinable) vers la feuille de route PDF, partagé avec l'équipe ; null = pas de partage */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $shareToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $sharedAt = null;

    // Tarifs et montants du devis (HT)
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $mealRate = '20.70';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $breakfastRate = '7.00';

    /** 0 = nuitées prises en charge par l'organisateur */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $nightRate = '0.00';

    /** € HT / km */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $truckRate = '1.10';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    public string $vatRate = '5.50';

    /** Cachet */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $fee = '0.00';



    /** @var Collection<int, Participation> */
    #[ORM\OneToMany(targetEntity: Participation::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $participations;

    /** @var Collection<int, Lodging> */
    #[ORM\OneToMany(targetEntity: Lodging::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $lodgings;

    /** @var Collection<int, TruckLeg> */
    #[ORM\OneToMany(targetEntity: TruckLeg::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $trucks;

    /** @var Collection<int, ExtraCost> */
    #[ORM\OneToMany(targetEntity: ExtraCost::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $extras;

    /** @var Collection<int, RoadmapSection> */
    #[ORM\OneToMany(targetEntity: RoadmapSection::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    public Collection $sections;

    /** @var Collection<int, ScheduleItem> */
    #[ORM\OneToMany(targetEntity: ScheduleItem::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['day' => 'ASC', 'time' => 'ASC'])]
    public Collection $schedule;

    public function __construct(Show $show, string $venue, \DateTimeImmutable $firstDate, \DateTimeImmutable $lastDate)
    {
        $this->show = $show;
        $this->venue = $venue;
        $this->firstDate = $firstDate;
        $this->lastDate = $lastDate;
        $this->participations = new ArrayCollection();
        $this->lodgings = new ArrayCollection();
        $this->trucks = new ArrayCollection();
        $this->extras = new ArrayCollection();
        $this->sections = new ArrayCollection();
        $this->schedule = new ArrayCollection();
    }

    /** Début de la présence de l'équipe (arrivées incluses). */
    public function start(): \DateTimeImmutable
    {
        return min(array_filter([$this->techArrival, $this->artisticArrival, $this->firstDate]));
    }

    public function end(): \DateTimeImmutable
    {
        return max(array_filter([$this->departure, $this->lastDate]));
    }

    /** @return list<\DateTimeImmutable> */
    public function spanDates(): array
    {
        $out = [];
        for ($d = $this->start(); $d <= $this->end(); $d = $d->modify('+1 day')) {
            $out[] = $d;
        }

        return $out;
    }

    public function overlaps(self $other): bool
    {
        return $other->id !== $this->id && $this->start() <= $other->end() && $other->start() <= $this->end();
    }
}
