<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Membre de la compagnie (artiste, technicien, production), réutilisable sur plusieurs dates. */
#[ORM\Entity]
class Person
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'people')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Company $company;

    #[ORM\Column(length: 120)]
    public string $lastName = '';

    #[ORM\Column(length: 120)]
    public string $firstName = '';

    #[ORM\Column(length: 120)]
    public string $function = '';

    #[ORM\Column(length: 60)]
    public string $phone = '';

    #[ORM\Column(length: 255)]
    public string $email = '';

    #[ORM\Column(length: 255)]
    public string $diet = '';

    #[ORM\Column(length: 120)]
    public string $homeCity = '';

    /** Identifiant de l'objet correspondant dans Orfeo (import), pour ne pas le recréer. */
    #[ORM\Column(nullable: true)]
    public ?int $orfeoId = null;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    public function fullName(): string
    {
        return trim(mb_strtoupper($this->lastName).' '.$this->firstName);
    }
}
