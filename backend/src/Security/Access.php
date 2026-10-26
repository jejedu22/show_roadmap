<?php

namespace App\Security;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Cloisonnement : un utilisateur ne voit que les compagnies qui lui sont attribuées (sauf admin). */
final class Access
{
    public static function canSee(User $user, Company $company): bool
    {
        return $user->isAdmin || $user->companies->exists(fn ($k, Company $c) => $c->id === $company->id);
    }

    public static function company(User $user, ?Company $company): Company
    {
        if (!$company || !self::canSee($user, $company)) {
            throw new NotFoundHttpException('Compagnie introuvable');
        }

        return $company;
    }

    public static function event(User $user, ?Event $event): Event
    {
        if (!$event || !self::canSee($user, $event->show->company)) {
            throw new NotFoundHttpException('Date introuvable');
        }

        return $event;
    }
}
