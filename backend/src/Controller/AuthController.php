<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserToken;
use App\Entity\TrustedDevice;
use App\Security\Permissions;
use App\Security\TrustedDevices;
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
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

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
    public function config(GoogleOAuth $google, SncfTimetable $sncf, #[Autowire(env: 'MAILER_DSN')] string $mailerDsn): JsonResponse
    {
        $user = $this->getUser();

        return $this->json(['google' => $google->enabled(), 'sncf' => $sncf->enabled(), 'passwordReset' => self::mailEnabled($mailerDsn),
            'sncfCoverage' => $sncf->enabled() ? $sncf->coverage() : null,
            'user' => $user instanceof User ? $this->presenter->user($user) : null]);
    }

    #[Route('/api/me', methods: ['PUT'])]
    public function updateMe(Request $request, UserPasswordHasherInterface $hasher, PasswordPolicy $passwords, Security $security, TrustedDevices $devices): JsonResponse
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
            // Appareils de confiance révoqués ; celui-ci le redevient s'il l'était
            $trusted = null !== ($series = $devices->currentSeries()) && array_filter($devices->of($user), fn ($d) => $d->series === $series);
            $revoked = $devices->revokeAll($user);
            $this->audit->log('user.password_change', $user->email, $revoked ? ['appareils révoqués' => $revoked] : []);
        }
        $this->em->flush();
        if ('' !== $new) {
            // Les autres sessions sont fermées (empreinte changée) ; celle-ci est renouvelée
            $security->login($user, 'json_login', 'main', $trusted ? [(new RememberMeBadge())->enable()] : []);
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
        $t = $tokens->find($token) ?? throw new NotFoundHttpException('Ce lien a expiré ou a déjà servi. Demandez-en un nouveau (« Mot de passe oublié ? ») ou adressez-vous à un administrateur.');

        return $this->json(['email' => $t->user->email, 'name' => $t->user->name, 'type' => $t->type, 'minLength' => PasswordPolicy::MIN_LENGTH]);
    }

    #[Route('/api/auth/token/{token}', methods: ['POST'])]
    public function tokenUse(string $token, Request $request, UserTokens $tokens, PasswordPolicy $passwords, Security $security, TrustedDevices $devices): JsonResponse
    {
        $t = $tokens->find($token) ?? throw new NotFoundHttpException('Ce lien a expiré ou a déjà servi. Demandez-en un nouveau (« Mot de passe oublié ? ») ou adressez-vous à un administrateur.');
        $d = $this->body($request);
        $passwords->set($t->user, (string) ($d['password'] ?? ''));
        if ('' !== Input::str($d, 'name')) {
            $t->user->name = Input::str($d, 'name');
        }
        $t->usedAt = new \DateTimeImmutable();
        // Mot de passe réinitialisé : sessions « rester connecté » de tous les appareils révoquées
        $revoked = $devices->revokeAll($t->user);
        $this->audit->log(UserToken::INVITE === $t->type ? 'user.activate' : 'user.password_reset', $t->user->email,
            $revoked ? ['appareils révoqués' => $revoked] : [], actor: $t->user->email);
        $this->em->flush();
        $security->login($t->user, 'json_login', 'main');

        return $this->json($this->presenter->user($t->user));
    }

    // ---- Mot de passe oublié : lien de réinitialisation envoyé par e-mail ----

    /**
     * Réponse identique que le compte existe ou non (aucune adresse ne peut être devinée), avec des limites par IP et
     * par adresse. Seuls les comptes actifs reçoivent un lien, valable 1 heure.
     */
    #[Route('/api/auth/forgot', methods: ['POST'])]
    public function forgot(Request $request, UserTokens $tokens, MailerInterface $mailer,
        RateLimiterFactoryInterface $passwordResetIpLimiter, RateLimiterFactoryInterface $passwordResetEmailLimiter,
        #[Autowire(env: 'MAILER_DSN')] string $mailerDsn, #[Autowire(env: 'PUBLIC_URL')] string $publicUrl): JsonResponse
    {
        if (!self::mailEnabled($mailerDsn)) {
            throw new NotFoundHttpException('L\'envoi d\'e-mails n\'est pas configuré : demandez un lien à un administrateur.');
        }
        $email = mb_strtolower(Input::str($this->body($request), 'email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestHttpException('Adresse e-mail invalide.');
        }
        foreach ([$passwordResetIpLimiter->create((string) $request->getClientIp()), $passwordResetEmailLimiter->create($email)] as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(900, 'Trop de demandes : réessayez dans quelques minutes.');
            }
        }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user && $user->isActive) {
            $token = $tokens->issue($user, UserToken::RESET, '+1 hour');
            $this->em->flush();
            $link = rtrim($publicUrl ?: $request->getSchemeAndHttpHost(), '/').'/mot-de-passe/'.$token;
            try {
                $mailer->send((new TemplatedEmail())
                    ->to($user->email)
                    ->subject('Réinitialisation de votre mot de passe — Show Roadmap')
                    ->htmlTemplate('emails/password_reset.html.twig')
                    ->textTemplate('emails/password_reset.txt.twig')
                    ->context(['name' => $user->name, 'account' => $user->email, 'link' => $link]));
                $this->audit->log('user.password_reset_requested', $user->email, actor: '', flush: true);
            } catch (\Throwable $e) {
                $this->audit->log('user.password_reset_mail_failed', $user->email, ['erreur' => mb_substr($e->getMessage(), 0, 200)], actor: '', flush: true);
                throw new \RuntimeException('Envoi de l\'e-mail impossible pour le moment : réessayez plus tard ou adressez-vous à un administrateur.', 0, $e);
            }
        } else {
            $this->audit->log('user.password_reset_unknown', $email, actor: '', flush: true);
        }

        return $this->json(['message' => 'Si un compte actif correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d\'être envoyé. Pensez à vérifier les indésirables.']);
    }

    private static function mailEnabled(string $dsn): bool
    {
        return '' !== $dsn && !str_starts_with($dsn, 'null://');
    }

    // ---- Appareils de confiance (« rester connecté ») ----

    #[Route('/api/me/devices', methods: ['GET'])]
    public function devices(TrustedDevices $devices): JsonResponse
    {
        $current = $devices->currentSeries();

        return $this->json(array_map(fn (TrustedDevice $d) => ['id' => $d->id, 'label' => $d->label, 'ip' => $d->ip,
            'createdAt' => $d->createdAt->format(\DATE_ATOM), 'lastUsed' => $d->lastUsed->format(\DATE_ATOM), 'current' => $d->series === $current],
            $devices->of($this->me())));
    }

    /** Révoque un appareil (le sien uniquement) ; sans identifiant, tous les autres appareils. */
    #[Route('/api/me/devices/{id<\d+>}', methods: ['DELETE'])]
    #[Route('/api/me/devices', methods: ['DELETE'])]
    public function revokeDevice(TrustedDevices $devices, ?int $id = null): JsonResponse
    {
        $me = $this->me();
        $current = $devices->currentSeries();
        if (null === $id) {
            $n = $devices->revokeAll($me, $current);
        } else {
            $d = $this->em->getRepository(TrustedDevice::class)->findOneBy(['id' => $id, 'user' => $me]) ?? throw new NotFoundHttpException('Appareil introuvable');
            $this->em->remove($d);
            $n = 1;
        }
        $this->audit->log('user.devices_revoked', $me->email, ['nombre' => $n]);
        $this->em->flush();
        $response = $this->devices($devices);
        if ($id && !array_filter(json_decode((string) $response->getContent(), true), fn ($d) => $d['current'])) {
            $response->headers->clearCookie('REMEMBERME', '/', null, true, true, 'lax'); // cet appareil n'est plus de confiance
        }

        return $response;
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
        $request->getSession()->set('google_remember', $request->query->getBoolean('remember'));

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
            $remember = (bool) $request->getSession()->remove('google_remember');
            $security->login($user, 'json_login', 'main', $remember ? [(new RememberMeBadge())->enable()] : []);
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
