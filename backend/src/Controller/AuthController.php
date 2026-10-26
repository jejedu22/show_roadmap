<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserToken;
use App\Security\Permissions;
use App\Service\GoogleOAuth;
use App\Service\PasswordPolicy;
use App\Service\UserTokens;
use App\Service\Input;
use App\Service\SncfTimetable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class AuthController extends ApiController
{
    /** Atteint seulement si json_login a réussi. */
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json($this->presenter->user($this->me()));
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu.');
    }

    #[Route('/api/config', methods: ['GET'])]
    public function config(GoogleOAuth $google, SncfTimetable $sncf): JsonResponse
    {
        $user = $this->getUser();

        return $this->json(['google' => $google->enabled(), 'sncf' => $sncf->enabled(),
            'sncfCoverage' => $sncf->enabled() ? $sncf->coverage() : null,
            'user' => $user instanceof User ? $this->presenter->user($user) : null]);
    }

    #[Route('/api/me', methods: ['PUT'])]
    public function updateMe(Request $request, UserPasswordHasherInterface $hasher, PasswordPolicy $passwords, Security $security): JsonResponse
    {
        $d = $this->body($request);
        $user = $this->me();
        if ('' !== Input::str($d, 'name')) {
            $user->name = Input::str($d, 'name');
        }
        $new = (string) ($d['newPassword'] ?? '');
        if ('' !== $new) {
            if (!$hasher->isPasswordValid($user, (string) ($d['currentPassword'] ?? ''))) {
                $this->audit->log('user.password_change_failed', $user->email, flush: true);
                throw new BadRequestHttpException('Mot de passe actuel incorrect.');
            }
            $passwords->set($user, $new);
            $this->audit->log('user.password_change', $user->email);
        }
        $this->em->flush();
        if ('' !== $new) {
            // Les autres sessions sont fermées (empreinte changée) ; celle-ci est renouvelée
            $security->login($user, 'json_login', 'main');
        }

        return $this->json($this->presenter->user($user));
    }

    /** Mes droits, compagnie par compagnie, en clair. */
    #[Route('/api/me/rights', methods: ['GET'])]
    public function rights(): JsonResponse
    {
        $me = $this->me();
        $label = fn (array $codes) => array_map(fn ($c) => ['code' => $c, 'label' => (Permissions::COMPANY[$c] ?? Permissions::GLOBAL[$c])[0]], $codes);

        return $this->json([
            'isAdmin' => $me->isAdmin,
            'groups' => $me->groups->map(fn ($g) => ['name' => $g->name, 'description' => $g->description])->getValues(),
            'global' => $label($this->authz->globalPerms($me)),
            'companies' => array_map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'permissions' => $label($this->authz->companyPerms($me, $c))],
                $this->authz->visibleCompanies($me)),
        ]);
    }

    // ---- Liens d'invitation / de réinitialisation (sans être connecté) ----

    #[Route('/api/auth/token/{token}', methods: ['GET'])]
    public function tokenInfo(string $token, UserTokens $tokens): JsonResponse
    {
        $t = $tokens->find($token) ?? throw new NotFoundHttpException('Ce lien a expiré ou a déjà servi. Demandez-en un nouveau à un administrateur.');

        return $this->json(['email' => $t->user->email, 'name' => $t->user->name, 'type' => $t->type, 'minLength' => PasswordPolicy::MIN_LENGTH]);
    }

    #[Route('/api/auth/token/{token}', methods: ['POST'])]
    public function tokenUse(string $token, Request $request, UserTokens $tokens, PasswordPolicy $passwords, Security $security): JsonResponse
    {
        $t = $tokens->find($token) ?? throw new NotFoundHttpException('Ce lien a expiré ou a déjà servi. Demandez-en un nouveau à un administrateur.');
        $d = $this->body($request);
        $passwords->set($t->user, (string) ($d['password'] ?? ''));
        if ('' !== Input::str($d, 'name')) {
            $t->user->name = Input::str($d, 'name');
        }
        $t->usedAt = new \DateTimeImmutable();
        $this->audit->log(UserToken::INVITE === $t->type ? 'user.activate' : 'user.password_reset', $t->user->email, actor: $t->user->email);
        $this->em->flush();
        $security->login($t->user, 'json_login', 'main');

        return $this->json($this->presenter->user($t->user));
    }

    // ---- Connexion Google : seuls les comptes déjà créés par un administrateur peuvent entrer ----

    #[Route('/api/auth/google', methods: ['GET'])]
    public function google(Request $request, GoogleOAuth $google, #[Autowire(env: 'PUBLIC_URL')] string $publicUrl): RedirectResponse
    {
        if (!$google->enabled()) {
            throw new NotFoundHttpException();
        }
        $state = bin2hex(random_bytes(24));
        $request->getSession()->set('google_state', $state);

        return $this->redirect($google->authorizeUrl($this->redirectUri($request, $publicUrl), $state));
    }

    #[Route('/api/auth/google/callback', methods: ['GET'])]
    public function googleCallback(Request $request, GoogleOAuth $google, Security $security,
        #[Autowire(env: 'PUBLIC_URL')] string $publicUrl): RedirectResponse
    {
        if (!$google->enabled()) {
            throw new NotFoundHttpException();
        }
        $expected = (string) $request->getSession()->remove('google_state');
        $state = (string) $request->query->get('state', '');
        $code = (string) $request->query->get('code', '');
        if ('' === $code || '' === $expected || !hash_equals($expected, $state) || $request->query->has('error')) {
            return $this->loginError('Connexion Google annulée ou invalide.');
        }
        try {
            $ident = $google->fetchIdentity($code, $this->redirectUri($request, $publicUrl));
        } catch (\RuntimeException $e) {
            return $this->loginError($e->getMessage());
        }
        $user = $ident['emailVerified'] ? $this->em->getRepository(User::class)->findOneBy(['email' => $ident['email']]) : null;
        if (!$user || !$user->isActive) {
            $this->audit->log('login.google_refused', $ident['email'], actor: '', flush: true);

            return $this->loginError('Aucun compte autorisé pour cette adresse Google. Demandez à un administrateur de le créer.');
        }
        try {
            $security->login($user, 'json_login', 'main');
        } catch (AuthenticationException) {
            return $this->loginError('Connexion refusée.');
        }

        return $this->redirect('/');
    }

    private function redirectUri(Request $request, string $publicUrl): string
    {
        return rtrim($publicUrl ?: $request->getSchemeAndHttpHost(), '/').'/api/auth/google/callback';
    }

    private function loginError(string $message): RedirectResponse
    {
        return $this->redirect('/login?error='.rawurlencode($message));
    }
}
