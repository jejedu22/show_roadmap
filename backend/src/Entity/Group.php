<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Groupe d'utilisateurs : des droits, un périmètre (toutes les compagnies ou certaines) et des membres. */
#[ORM\Entity]
#[ORM\Table(name: 'app_group')]
class Group
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    public string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $description = '';

    /** @var list<string> codes de App\Security\Permissions */
    #[ORM\Column(type: Types::JSON)]
    public array $permissions = [];

    #[ORM\Column]
    public bool $allCompanies = false;

    /** @var Collection<int, Company> */
    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'group_company')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    public Collection $companies;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'group_user')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['email' => 'ASC'])]
    public Collection $users;

    public function __construct(string $name = '', array $permissions = [])
    {
        $this->name = $name;
        $this->permissions = $permissions;
        $this->companies = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function covers(Company $company): bool
    {
        return $this->allCompanies || $this->companies->exists(fn ($k, Company $c) => $c->id === $company->id);
    }
}
