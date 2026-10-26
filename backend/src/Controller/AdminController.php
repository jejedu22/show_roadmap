<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Service\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Gestion des comptes (réservé ROLE_ADMIN via access_control). */
#[Route('/api/admin')]
class AdminController extends ApiController
{
    #[Route('/users', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json([
            'users' => array_map(fn (User $u) => $this->presenter->user($u), $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC'])),
            'companies' => array_map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name],
                $this->em->getRepository(Company::class)->findBy([], ['name' => 'ASC'])),
        ]);
    }

    #[Route('/users', methods: ['POST'])]
    public function create(Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $d = $this->body($request);
        $email = mb_strtolower(Input::str($d, 'email', 180));
        $password = (string) ($d['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            throw new BadRequestHttpException('E-mail invalide ou déjà utilisé');
        }
        if (mb_strlen($password) < 10) {
            throw new BadRequestHttpException('Mot de passe : 10 caractères minimum');
        }
        $user = new User();
        $user->email = $email;
        $user->name = Input::str($d, 'name');
        $user->isAdmin = (bool) ($d['isAdmin'] ?? false);
        $user->password = $hasher->hashPassword($user, $password);
        $this->em->persist($user);
        $this->em->flush();

        return $this->list();
    }

    #[Route('/users/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $user = $this->em->find(User::class, $id) ?? throw new NotFoundHttpException();
        $d = $this->body($request);
        if ($user->id !== $this->me()->id) { // on ne peut pas se retirer ses propres droits
            $user->isAdmin = (bool) ($d['isAdmin'] ?? $user->isAdmin);
            $user->isActive = (bool) ($d['isActive'] ?? $user->isActive);
        }
        if (isset($d['companyIds'])) {
            $ids = array_map('intval', (array) $d['companyIds']);
            $user->companies->clear();
            foreach ($ids ? $this->em->getRepository(Company::class)->findBy(['id' => $ids]) : [] as $c) {
                $user->companies->add($c);
            }
        }
        $password = (string) ($d['password'] ?? '');
        if ('' !== $password) {
            if (mb_strlen($password) < 10) {
                throw new BadRequestHttpException('Mot de passe : 10 caractères minimum');
            }
            $user->password = $hasher->hashPassword($user, $password);
        }
        $this->em->flush();

        return $this->list();
    }
}
