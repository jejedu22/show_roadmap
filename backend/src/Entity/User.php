<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public string $email = '';

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 255)]
    public string $password = '';

    #[ORM\Column]
    public bool $isAdmin = false;

    #[ORM\Column]
    public bool $isActive = true;

    /** @var Collection<int, Company> */
    #[ORM\ManyToMany(targetEntity: Company::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_company')]
    public Collection $companies;

    public function __construct()
    {
        $this->companies = new ArrayCollection();
    }

    public function getRoles(): array
    {
        return $this->isAdmin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }

    /** Un compte désactivé ou dont le mot de passe a changé perd sa session au prochain chargement. */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self && $user->id === $this->id && $user->password === $this->password
            && $user->isActive === $this->isActive && $user->isAdmin === $this->isAdmin;
    }

    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'password' => $this->password,
            'isActive' => $this->isActive, 'isAdmin' => $this->isAdmin];
    }

    public function __unserialize(array $data): void
    {
        $this->companies = new ArrayCollection();
        foreach ($data as $k => $v) {
            $this->$k = $v;
        }
    }
}
