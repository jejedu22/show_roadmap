<?php

namespace App\Security;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Droits effectifs d'un utilisateur. Les groupes sont relus à chaque requête : un changement de droits s'applique immédiatement.
 * Sans « company.view », la compagnie n'existe pas pour l'utilisateur (404) ; avec, un droit manquant donne 403.
 */
class Authz
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<string> droits sur une compagnie */
    public function companyPerms(User $user, Company $company): array
    {
        if ($user->isAdmin) {
            return array_keys(Permissions::COMPANY);
        }
        $perms = [];
        foreach ($user->groups as $g) {
            if ($g->covers($company)) {
                array_push($perms, ...$g->permissions);
            }
        }
        $perms = array_values(array_intersect(array_keys(Permissions::COMPANY), $perms));

        // Un droit de modification implique de pouvoir consulter ce qu'on modifie
        $implied = ['finance.edit' => 'finance.view', 'people.edit' => 'people.view'];
        foreach ($implied as $edit => $view) {
            if (in_array($edit, $perms, true) && !in_array($view, $perms, true)) {
                $perms[] = $view;
            }
        }

        return $perms && !in_array('company.view', $perms, true) ? ['company.view', ...$perms] : $perms;
    }

    /** @return list<string> droits d'administration */
    public function globalPerms(User $user): array
    {
        if ($user->isAdmin) {
            return array_keys(Permissions::GLOBAL);
        }
        $perms = [];
        foreach ($user->groups as $g) {
            array_push($perms, ...$g->permissions);
        }

        return array_values(array_intersect(array_keys(Permissions::GLOBAL), $perms));
    }

    public function can(User $user, string $perm, ?Company $company = null): bool
    {
        return isset(Permissions::GLOBAL[$perm]) ? in_array($perm, $this->globalPerms($user), true)
            : null !== $company && in_array($perm, $this->companyPerms($user, $company), true);
    }

    /** @return list<Company> */
    public function visibleCompanies(User $user): array
    {
        $all = $this->em->getRepository(Company::class)->findBy([], ['name' => 'ASC']);

        return array_values(array_filter($all, fn (Company $c) => $this->can($user, 'company.view', $c)));
    }

    public function company(User $user, ?Company $company, string $perm = 'company.view'): Company
    {
        if (!$company || !$this->can($user, 'company.view', $company)) {
            throw new NotFoundHttpException('Compagnie introuvable');
        }
        $this->deny($user, $perm, $company);

        return $company;
    }

    public function event(User $user, ?Event $event, string $perm = 'company.view'): Event
    {
        if (!$event || !$this->can($user, 'company.view', $event->show->company)) {
            throw new NotFoundHttpException('Date introuvable');
        }
        $this->deny($user, $perm, $event->show->company);

        return $event;
    }

    public function global(User $user, string $perm): void
    {
        $this->deny($user, $perm);
    }

    private function deny(User $user, string $perm, ?Company $company = null): void
    {
        if (!$this->can($user, $perm, $company)) {
            $label = Permissions::COMPANY[$perm][0] ?? Permissions::GLOBAL[$perm][0] ?? $perm;
            throw new AccessDeniedHttpException("Droit nécessaire : « $label ». Demandez-le à un administrateur.");
        }
    }
}
