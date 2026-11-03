<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dépense (montant positif) ou remboursement (montant négatif) sur l'aller ou le retour d'une personne : taxi, parking,
 * frais d'échange… Comptée dans le coût des voyages, jamais affichée dans la feuille de route.
 */
#[ORM\Entity]
class TravelExpense
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'expenses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Participation $participation;

    /** outbound / return */
    #[ORM\Column(length: 10)]
    public string $direction = 'outbound';

    /** Entrée de la liste « Types de dépense » */
    #[ORM\Column(length: 120)]
    public string $kind = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    public string $amount = '0.00';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    public \DateTimeImmutable $day;

    #[ORM\Column(length: 255)]
    public string $comment = '';

    public function __construct(Participation $participation, \DateTimeImmutable $day)
    {
        $this->participation = $participation;
        $this->day = $day;
    }
}
