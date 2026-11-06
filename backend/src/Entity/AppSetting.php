<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Réglage global de l'application (thème de couleurs…), modifié dans l'administration. */
#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class AppSetting
{
    #[ORM\Id, ORM\Column(length: 60)]
    public string $name;

    #[ORM\Column(type: Types::TEXT)]
    public string $value = '';

    public function __construct(string $name, string $value = '')
    {
        $this->name = $name;
        $this->value = $value;
    }
}
