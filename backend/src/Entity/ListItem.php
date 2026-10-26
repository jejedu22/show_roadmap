<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Entrée d'une liste paramétrée dans l'administration (fonctions des personnes, intitulés de planning). */
#[ORM\Entity]
#[ORM\Table(name: 'list_item')]
#[ORM\UniqueConstraint(name: 'list_item_label', columns: ['kind', 'label'])]
class ListItem
{
    public const KINDS = ['function' => 'Fonctions', 'schedule' => 'Intitulés de planning'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20)]
    public string $kind;

    #[ORM\Column(length: 120)]
    public string $label;

    #[ORM\Column]
    public int $position = 0;

    public function __construct(string $kind, string $label, int $position = 0)
    {
        $this->kind = $kind;
        $this->label = $label;
        $this->position = $position;
    }
}
