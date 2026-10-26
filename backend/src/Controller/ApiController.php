<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\User;
use App\Security\Authz;
use App\Service\Audit;
use App\Service\Lists;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class ApiController extends AbstractController
{
    public function __construct(protected EntityManagerInterface $em, protected Presenter $presenter, protected Authz $authz, protected Audit $audit,
        protected Lists $lists)
    {
    }

    protected function me(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    protected function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new BadRequestHttpException('JSON invalide');
        }

        return $data;
    }

    /** Compagnie accessible avec le droit demandé (404 si invisible, 403 si droit manquant). */
    protected function company(int $id, string $perm = 'company.view'): Company
    {
        return $this->authz->company($this->me(), $this->em->find(Company::class, $id), $perm);
    }

    protected function event(int $id, string $perm = 'company.view'): Event
    {
        return $this->authz->event($this->me(), $this->em->find(Event::class, $id), $perm);
    }

    /** Enregistre puis renvoie la date rechargée (collections triées). */
    protected function savedEvent(Event $event): JsonResponse
    {
        $this->em->flush();
        $id = $event->id;
        $this->em->clear();

        return $this->json($this->presenter->event($this->em->find(Event::class, $id), $this->reloadedMe()));
    }

    protected function savedCompany(Company $company): JsonResponse
    {
        $this->em->flush();
        $id = $company->id;
        $this->em->clear();

        return $this->json($this->presenter->company($this->em->find(Company::class, $id), $this->reloadedMe()));
    }

    /** Utilisateur courant rechargé après un clear() de l'EntityManager (ses groupes servent au filtrage). */
    protected function reloadedMe(): User
    {
        return $this->em->find(User::class, $this->me()->id);
    }
}
