<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\User;
use App\Security\Access;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

abstract class ApiController extends AbstractController
{
    public function __construct(protected EntityManagerInterface $em, protected Presenter $presenter)
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

    protected function company(int $id): Company
    {
        return Access::company($this->me(), $this->em->find(Company::class, $id));
    }

    protected function event(int $id): Event
    {
        return Access::event($this->me(), $this->em->find(Event::class, $id));
    }

    /** Enregistre puis renvoie la date rechargée (collections triées). */
    protected function savedEvent(Event $event): JsonResponse
    {
        $this->em->flush();
        $id = $event->id;
        $this->em->clear();

        return $this->json($this->presenter->event($this->em->find(Event::class, $id)));
    }

    protected function savedCompany(Company $company): JsonResponse
    {
        $this->em->flush();
        $id = $company->id;
        $this->em->clear();

        return $this->json($this->presenter->company($this->em->find(Company::class, $id)));
    }
}
