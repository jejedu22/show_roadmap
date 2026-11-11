<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Logo d'une compagnie (PNG réencodé), à part de la compagnie pour ne le charger qu'à l'affichage. */
#[ORM\Entity]
#[ORM\Table(name: 'company_logo')]
class CompanyLogo
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Company $company;

    /** Image PNG */
    #[ORM\Column(type: Types::BLOB)]
    public mixed $data;

    public function __construct(Company $company, string $png)
    {
        $this->company = $company;
        $this->data = $png;
    }

    public function png(): string
    {
        return is_resource($this->data) ? (string) stream_get_contents($this->data, -1, 0) : (string) $this->data;
    }
}
