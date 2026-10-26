<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Company
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $notes = '';

    /** Clé d'API Orfeo, chiffrée (voir Secrets) ; null = non connectée. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $orfeoToken = null;

    /** Nom de l'organisation Orfeo reliée, pour l'affichage. */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    public string $orfeoOrganization = '';

    /** @var Collection<int, Show> */
    #[ORM\OneToMany(targetEntity: Show::class, mappedBy: 'company', cascade: ['remove'])]
    #[ORM\OrderBy(['title' => 'ASC'])]
    public Collection $shows;

    /** @var Collection<int, Person> */
    #[ORM\OneToMany(targetEntity: Person::class, mappedBy: 'company', cascade: ['remove'])]
    #[ORM\OrderBy(['lastName' => 'ASC', 'firstName' => 'ASC'])]
    public Collection $people;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->shows = new ArrayCollection();
        $this->people = new ArrayCollection();
    }
}
