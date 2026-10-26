<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Un spectacle du répertoire de la compagnie. */
#[ORM\Entity]
#[ORM\Table(name: 'shows')]
class Show
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'shows')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Company $company;

    #[ORM\Column(length: 255)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $description = '';

    /** @var Collection<int, Event> */
    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'show', cascade: ['remove'])]
    #[ORM\OrderBy(['firstDate' => 'ASC'])]
    public Collection $events;

    public function __construct(Company $company, string $title)
    {
        $this->company = $company;
        $this->title = $title;
        $this->events = new ArrayCollection();
    }
}
