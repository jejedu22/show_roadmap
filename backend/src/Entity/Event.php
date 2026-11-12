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

    /** Dernier jour où le lien de la feuille de route fonctionne (il contient les coordonnées de l'équipe) */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $shareExpiresOn = null;

    /** Lien public (non devinable) vers l'export Excel des coûts, distinct de celui de la feuille de route ; null = pas de partage */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $costShareToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $costSharedAt = null;

    /** Dernier jour où le lien des coûts fonctionne */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $costShareExpiresOn = null;

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

    /** Budget transport (HT) : ligne du devis en plus du transport réel de l'équipe */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    public string $transportBudget = '0.00';



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

    /** @var Collection<int, Invitation> invitations Cie et Pro, sur le contingent du lieu */
    #[ORM\OneToMany(targetEntity: Invitation::class, mappedBy: 'event', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['kind' => 'ASC', 'lastName' => 'ASC', 'firstName' => 'ASC'])]
    public Collection $invitations;

    /** Places d'invitation mises à disposition par le lieu pour toute la date, quand le planning n'a pas de représentation */
    #[ORM\Column(options: ['default' => 0])]
    public int $invitationQuota = 0;

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
        $this->invitations = new ArrayCollection();
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

    /** Jours de validité d'un lien de partage après la fin de la date (ou après aujourd'hui pour une date passée). */
    public const SHARE_DAYS = 30;

    /** Expiration par défaut d'un lien de partage : 30 jours après la fin de la date, et au moins 30 jours à partir d'aujourd'hui. */
    public function defaultShareExpiry(): \DateTimeImmutable
    {
        return max($this->end(), self::today())->modify('+'.self::SHARE_DAYS.' days');
    }

    /** Expiration la plus lointaine qu'on peut choisir : un an après la fin de la date (ou après aujourd'hui). */
    public function maxShareExpiry(): \DateTimeImmutable
    {
        return max($this->end(), self::today())->modify('+1 year');
    }

    /** Un lien fonctionne jusqu'à la fin de son jour d'expiration (heure de Paris) ; sans date, il a expiré. */
    public static function linkValid(?\DateTimeImmutable $expiresOn): bool
    {
        return null !== $expiresOn && $expiresOn->format('Y-m-d') >= self::today()->format('Y-m-d');
    }

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
    }

    /** @return list<\DateTimeImmutable> jours de la date, élargis aux jours de présence des participants (voyages) */
    public function spanDates(): array
    {
        [$from, $to] = [$this->start(), $this->end()];
        foreach ($this->participations as $p) {
            foreach ($p->days as $d) {
                $from = min($from, $d->day);
                $to = max($to, $d->day);
            }
        }
        $out = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $out[] = $d;
        }

        return $out;
    }

    public function overlaps(self $other): bool
    {
        return $other->id !== $this->id && $this->start() <= $other->end() && $other->start() <= $this->end();
    }
}
