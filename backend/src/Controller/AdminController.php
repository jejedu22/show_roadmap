<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\UserToken;
use App\Security\Permissions;
use App\Service\Input;
use App\Service\PasswordPolicy;
use App\Service\UserTokens;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptes, groupes et journal. Garde-fous :
 * - seul un super-administrateur nomme un super-administrateur, et attribue ou modifie des droits d'administration ;
 * - un gestionnaire de groupes ne donne accès qu'aux compagnies qu'il voit lui-même ;
 * - on ne peut ni se désactiver, ni se supprimer, ni retirer le dernier super-administrateur actif.
 */
#[Route('/api/admin')]
class AdminController extends ApiController
{
    // ---------- Utilisateurs ----------

    #[Route('/users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        $this->authz->global($this->me(), 'users.manage');

        return $this->usersPayload();
    }

    /** Invitation : le compte est créé sans mot de passe utilisable ; la personne choisit le sien via le lien. */
    #[Route('/users', methods: ['POST'])]
    public function invite(Request $request, PasswordPolicy $passwords, UserTokens $tokens): JsonResponse
    {
        $this->authz->global($this->me(), 'users.manage');
        $d = $this->body($request);
        $email = mb_strtolower(Input::str($d, 'email', 180));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Adresse e-mail invalide');
        }
        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            throw new ConflictHttpException('Un compte existe déjà avec cette adresse');
        }
        $user = new User();
        [$user->email, $user->name] = [$email, Input::str($d, 'name')];
        $passwords->lock($user);
        $this->em->persist($user);
        $this->setGroups($user, (array) ($d['groupIds'] ?? []));
        $token = $tokens->issue($user, UserToken::INVITE);
        $this->audit->log('user.invite', $email, ['groups' => $user->groups->map(fn ($g) => $g->name)->getValues()]);
        $this->em->flush();

        return $this->usersPayload(['link' => $this->tokenUrl($request, $token), 'linkFor' => $email, 'linkType' => 'invite']);
    }

    #[Route('/users/{id<\d+>}', methods: ['PUT'])]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        $me = $this->me();
        $this->authz->global($me, 'users.manage');
        $user = $this->userOr404($id);
        $d = $this->body($request);
        $changes = [];
        if (array_key_exists('name', $d)) {
            $user->name = Input::str($d, 'name');
        }
        if (array_key_exists('isActive', $d) && (bool) $d['isActive'] !== $user->isActive) {
            if ($user->id === $me->id) {
                throw new BadRequestHttpException('Vous ne pouvez pas désactiver votre propre compte.');
            }
            $this->guardSuperAdmin($user, 'désactiver');
            $user->isActive = (bool) $d['isActive'];
            $changes['actif'] = $user->isActive;
        }
        if (array_key_exists('isAdmin', $d) && (bool) $d['isAdmin'] !== $user->isAdmin) {
            if (!$me->isAdmin) {
                throw new AccessDeniedHttpException('Seul un super-administrateur peut nommer ou retirer un super-administrateur.');
            }
            if ($user->id === $me->id) {
                throw new BadRequestHttpException('Vous ne pouvez pas retirer vos propres droits de super-administrateur.');
            }
            $user->isAdmin = (bool) $d['isAdmin'];
            $changes['superAdmin'] = $user->isAdmin;
        }
        if (array_key_exists('groupIds', $d)) {
            $before = $user->groups->map(fn ($g) => $g->name)->getValues();
            $this->setGroups($user, (array) $d['groupIds']);
            $after = $user->groups->map(fn ($g) => $g->name)->getValues();
            if ($before != $after) {
                $changes['groupes'] = $after;
            }
        }
        if ($changes) {
            $this->audit->log('user.update', $user->email, $changes);
        }
        $this->em->flush();

        return $this->usersPayload();
    }

    /** Lien de réinitialisation (24 h) ou nouvelle invitation (7 jours) si le compte n'a jamais été activé. */
    #[Route('/users/{id<\d+>}/link', methods: ['POST'])]
    public function link(int $id, Request $request, UserTokens $tokens): JsonResponse
    {
        $this->authz->global($this->me(), 'users.manage');
        $user = $this->userOr404($id);
        if (!$user->isActive) {
            throw new BadRequestHttpException('Réactivez d\'abord le compte.');
        }
        if ($user->isAdmin && !$this->me()->isAdmin) {
            throw new AccessDeniedHttpException('Seul un super-administrateur peut réinitialiser un super-administrateur.');
        }
        $type = null === $user->lastLoginAt ? UserToken::INVITE : UserToken::RESET;
        $token = $tokens->issue($user, $type);
        $this->audit->log(UserToken::INVITE === $type ? 'user.invite_link' : 'user.reset_link', $user->email);
        $this->em->flush();

        return $this->usersPayload(['link' => $this->tokenUrl($request, $token), 'linkFor' => $user->email, 'linkType' => $type]);
    }

    #[Route('/users/{id<\d+>}', methods: ['DELETE'])]
    public function deleteUser(int $id): JsonResponse
    {
        $me = $this->me();
        $this->authz->global($me, 'users.manage');
        $user = $this->userOr404($id);
        if ($user->id === $me->id) {
            throw new BadRequestHttpException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        $this->guardSuperAdmin($user, 'supprimer');
        $this->audit->log('user.delete', $user->email);
        $this->em->remove($user);
        $this->em->flush();

        return $this->usersPayload();
    }

    // ---------- Groupes ----------

    #[Route('/groups', methods: ['GET'])]
    public function groups(): JsonResponse
    {
        $this->authz->global($this->me(), 'groups.manage');

        return $this->groupsPayload();
    }

    #[Route('/groups', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        $this->authz->global($this->me(), 'groups.manage');
        $group = new Group();
        $this->fillGroup($group, $this->body($request));
        $this->em->persist($group);
        $this->audit->log('group.create', $group->name, $this->describe($group));
        $this->em->flush();

        return $this->groupsPayload();
    }

    #[Route('/groups/{id<\d+>}', methods: ['PUT'])]
    public function updateGroup(int $id, Request $request): JsonResponse
    {
        $this->authz->global($this->me(), 'groups.manage');
        $group = $this->groupOr404($id);
        $this->guardAdminGroup($group);
        $this->fillGroup($group, $this->body($request));
        $this->audit->log('group.update', $group->name, $this->describe($group));
        $this->em->flush();

        return $this->groupsPayload();
    }

    #[Route('/groups/{id<\d+>}', methods: ['DELETE'])]
    public function deleteGroup(int $id): JsonResponse
    {
        $this->authz->global($this->me(), 'groups.manage');
        $group = $this->groupOr404($id);
        $this->guardAdminGroup($group);
        $this->audit->log('group.delete', $group->name, ['membres' => $group->users->map(fn ($u) => $u->email)->getValues()]);
        $this->em->remove($group);
        $this->em->flush();

        return $this->groupsPayload();
    }

    // ---------- Journal ----------

    #[Route('/audit', methods: ['GET'])]
    public function auditLog(Request $request): JsonResponse
    {
        $this->authz->global($this->me(), 'users.manage');
        $q = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(AuditLog::class)->createQueryBuilder('a')->orderBy('a.at', 'DESC')->setMaxResults(300);
        if ('' !== $q) {
            $qb->where('a.actor LIKE :q OR a.target LIKE :q OR a.action LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }

        return $this->json(array_map(fn (AuditLog $a) => ['id' => $a->id, 'at' => $a->at->format(\DATE_ATOM), 'actor' => $a->actor,
            'action' => $a->action, 'target' => $a->target, 'details' => $a->details, 'ip' => $a->ip], $qb->getQuery()->getResult()));
    }

    // ---------- Outils ----------

    private function usersPayload(array $extra = []): JsonResponse
    {
        $pending = [];
        foreach ($this->em->getRepository(UserToken::class)->findBy(['type' => UserToken::INVITE, 'usedAt' => null]) as $t) {
            if ($t->expiresAt > new \DateTimeImmutable()) {
                $pending[$t->user->id] = $t->expiresAt->format(\DATE_ATOM);
            }
        }

        return $this->json([
            'users' => array_map(fn (User $u) => [
                'id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'isAdmin' => $u->isAdmin, 'isActive' => $u->isActive,
                'createdAt' => $u->createdAt->format(\DATE_ATOM), 'lastLoginAt' => $u->lastLoginAt?->format(\DATE_ATOM),
                'invitationExpiresAt' => $pending[$u->id] ?? null,
                'groupIds' => $u->groups->map(fn ($g) => $g->id)->getValues(),
            ], $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC'])),
            'groups' => array_map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'admin' => $this->hasGlobal($g)],
                $this->em->getRepository(Group::class)->findBy([], ['name' => 'ASC'])),
        ] + $extra);
    }

    private function groupsPayload(): JsonResponse
    {
        $catalog = fn (array $list) => array_map(fn ($code, $v) => ['code' => $code, 'label' => $v[0], 'help' => $v[1]], array_keys($list), $list);

        return $this->json([
            'groups' => array_map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'description' => $g->description,
                'permissions' => $g->permissions, 'allCompanies' => $g->allCompanies, 'admin' => $this->hasGlobal($g),
                'companyIds' => $g->companies->map(fn ($c) => $c->id)->getValues(),
                'userIds' => $g->users->map(fn ($u) => $u->id)->getValues()],
                $this->em->getRepository(Group::class)->findBy([], ['name' => 'ASC'])),
            'catalog' => ['company' => $catalog(Permissions::COMPANY), 'global' => $catalog(Permissions::GLOBAL)],
            'companies' => array_map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name], $this->authz->visibleCompanies($this->me())),
            'users' => array_map(fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'isActive' => $u->isActive],
                $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC'])),
            'canGrantAdmin' => $this->me()->isAdmin,
        ]);
    }

    /** Remplace les groupes d'un utilisateur ; les groupes d'administration ne sont modifiables que par un super-administrateur. */
    private function setGroups(User $user, array $ids): void
    {
        $wanted = $ids ? $this->em->getRepository(Group::class)->findBy(['id' => array_map('intval', $ids)]) : [];
        $current = $user->groups->toArray();
        foreach ([...array_udiff($wanted, $current, fn ($a, $b) => $a->id <=> $b->id), ...array_udiff($current, $wanted, fn ($a, $b) => $a->id <=> $b->id)] as $changed) {
            $this->guardAdminGroup($changed);
        }
        foreach ($current as $g) {
            if (!in_array($g, $wanted, true)) {
                $g->users->removeElement($user);
                $user->groups->removeElement($g);
            }
        }
        foreach ($wanted as $g) {
            if (!$g->users->contains($user)) {
                $g->users->add($user);
                $user->groups->add($g);
            }
        }
    }

    private function fillGroup(Group $group, array $d): void
    {
        $me = $this->me();
        $group->name = Input::str($d, 'name', 120) ?: throw new BadRequestHttpException('Nom du groupe obligatoire');
        $other = $this->em->getRepository(Group::class)->findOneBy(['name' => $group->name]);
        if ($other && $other !== $group) {
            throw new ConflictHttpException('Un groupe porte déjà ce nom');
        }
        $group->description = Input::text($d, 'description');
        $perms = Permissions::clean((array) ($d['permissions'] ?? []));
        if (!$me->isAdmin && array_intersect($perms, array_keys(Permissions::GLOBAL))) {
            throw new AccessDeniedHttpException('Seul un super-administrateur peut accorder des droits d\'administration.');
        }
        $group->permissions = $perms;
        $all = (bool) ($d['allCompanies'] ?? false);
        if ($all && !$group->allCompanies && !$me->isAdmin) {
            throw new AccessDeniedHttpException('Seul un super-administrateur peut ouvrir un groupe à toutes les compagnies.');
        }
        $group->allCompanies = $all;
        $visible = array_column(array_map(fn ($c) => ['id' => $c->id, 'c' => $c], $this->authz->visibleCompanies($me)), 'c', 'id');
        $group->companies->clear();
        foreach ((array) ($d['companyIds'] ?? []) as $cid) {
            $c = $visible[(int) $cid] ?? throw new AccessDeniedHttpException('Vous ne pouvez donner accès qu\'aux compagnies que vous voyez.');
            $group->companies->add($c);
        }
        if (array_key_exists('userIds', $d)) {
            $ids = array_map('intval', (array) $d['userIds']);
            foreach ($group->users->toArray() as $u) {
                if (!in_array($u->id, $ids, true)) {
                    $group->users->removeElement($u);
                }
            }
            foreach ($ids ? $this->em->getRepository(User::class)->findBy(['id' => $ids]) : [] as $u) {
                if (!$group->users->contains($u)) {
                    $group->users->add($u);
                }
            }
        }
    }

    private function hasGlobal(Group $g): bool
    {
        return (bool) array_intersect($g->permissions, array_keys(Permissions::GLOBAL));
    }

    private function guardAdminGroup(Group $g): void
    {
        if ($this->hasGlobal($g) && !$this->me()->isAdmin) {
            throw new AccessDeniedHttpException("Le groupe « {$g->name} » donne des droits d'administration : seul un super-administrateur peut le modifier.");
        }
    }

    /** Un super-administrateur ne peut être désactivé ou supprimé que par un autre, et jamais le dernier actif. */
    private function guardSuperAdmin(User $user, string $verb): void
    {
        if (!$user->isAdmin) {
            return;
        }
        if (!$this->me()->isAdmin) {
            throw new AccessDeniedHttpException("Seul un super-administrateur peut $verb un super-administrateur.");
        }
        $active = $this->em->getRepository(User::class)->count(['isAdmin' => true, 'isActive' => true]);
        if ($user->isActive && $active <= 1) {
            throw new BadRequestHttpException("Impossible de $verb le dernier super-administrateur actif.");
        }
    }

    private function describe(Group $g): array
    {
        return ['droits' => $g->permissions, 'toutesCompagnies' => $g->allCompanies,
            'compagnies' => $g->companies->map(fn ($c) => $c->name)->getValues(), 'membres' => $g->users->map(fn ($u) => $u->email)->getValues()];
    }

    private function userOr404(int $id): User
    {
        return $this->em->find(User::class, $id) ?? throw new NotFoundHttpException('Compte introuvable');
    }

    private function groupOr404(int $id): Group
    {
        return $this->em->find(Group::class, $id) ?? throw new NotFoundHttpException('Groupe introuvable');
    }

    private function tokenUrl(Request $request, string $token): string
    {
        $base = rtrim($this->getParameter('app.public_url') ?: $request->getSchemeAndHttpHost(), '/');

        return "$base/mot-de-passe/$token";
    }
}
