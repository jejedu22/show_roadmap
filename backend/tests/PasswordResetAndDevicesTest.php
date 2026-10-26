<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\TrustedDevice;
use Symfony\Component\BrowserKit\Cookie;

/** Mot de passe oublié (lien par e-mail) et appareils de confiance (« rester connecté »). */
class PasswordResetAndDevicesTest extends ApiTestCase
{
    private const NEW = 'Nouvelle-Valise-Bleue-2027';

    private function forgot(string $email): ?array
    {
        return $this->call('POST', '/api/auth/forgot', ['email' => $email]);
    }

    public function testForgotPasswordSendsSingleUseLink(): void
    {
        self::assertTrue($this->call('GET', '/api/config')['passwordReset']);

        // Même réponse pour une adresse inconnue : rien à deviner, aucun e-mail
        $unknown = $this->forgot('personne@test.io');
        self::assertSame(200, $this->code());
        self::assertEmailCount(0);

        $known = $this->forgot('ADMIN@test.io');
        self::assertSame($unknown, $known);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailAddressContains($mail, 'To', 'admin@test.io');
        self::assertEmailHtmlBodyContains($mail, 'valable <b>1 heure</b>');
        preg_match('#https://roadmap\.test/mot-de-passe/([0-9a-f]{64})#', $mail->getTextBody(), $m);
        self::assertNotEmpty($m, 'lien absolu vers l\'application');

        $info = $this->call('GET', "/api/auth/token/{$m[1]}");
        self::assertSame(['admin@test.io', 'reset'], [$info['email'], $info['type']]);
        $this->call('POST', "/api/auth/token/{$m[1]}", ['password' => self::NEW]);
        self::assertSame(200, $this->code());
        $this->call('POST', "/api/auth/token/{$m[1]}", ['password' => self::NEW.'-bis']);
        self::assertSame(404, $this->code(), 'lien à usage unique');

        $this->call('POST', '/api/logout');
        $this->login('admin@test.io', self::NEW);
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'user.password_reset_requested']));
    }

    public function testForgotPasswordIsRateLimitedAndSkipsInactiveAccounts(): void
    {
        $u = $this->createUser('off@test.io', 'off-password-1234');
        $u->isActive = false;
        $this->em()->flush();
        $this->forgot('off@test.io');
        self::assertSame(200, $this->code());
        self::assertEmailCount(0, null, 'compte désactivé : pas de lien');

        $this->forgot('admin@test.io');
        $this->forgot('admin@test.io');
        $this->forgot('admin@test.io');
        $res = $this->forgot('admin@test.io');
        self::assertSame(429, $this->code());
        self::assertStringContainsString('Trop de demandes', $res['error']);

        $this->forgot('pas-une-adresse');
        self::assertSame(400, $this->code());
    }

    public function testTrustedDeviceKeepsSessionAndCanBeRevoked(): void
    {
        // Sans la case : pas de cookie
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'admin-password-123']);
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->call('POST', '/api/logout');

        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15');
        $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'admin-password-123', '_remember_me' => true]);
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        $device = $this->em()->getRepository(TrustedDevice::class)->findOneBy([]);
        self::assertSame('Safari sur Mac', $device->label);
        $secret = explode(':', \Symfony\Component\Security\Http\RememberMe\RememberMeDetails::fromRawCookie(urldecode($cookie->getValue()))->getValue())[1];
        self::assertSame(hash('sha256', $secret), explode('.', $device->tokenHash)[0], 'seule l\'empreinte du jeton est stockée');

        // Nouvelle visite sans session : reconnu grâce au cookie
        $fresh = function () use (&$cookie) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(new Cookie('REMEMBERME', $cookie->getValue()));
        };
        $fresh();
        $list = $this->call('GET', '/api/me/devices');
        self::assertSame(200, $this->code());
        self::assertSame([['Safari sur Mac', true]], array_map(fn ($d) => [$d['label'], $d['current']], $list));

        // Reprise après une heure : jeton renouvelé ; l'ancien reste valable une minute, puis il est refusé (et l'appareil révoqué)
        $this->em()->getConnection()->executeStatement('UPDATE trusted_device SET last_used = NOW() - INTERVAL 1 HOUR');
        $fresh();
        $this->call('GET', '/api/me/devices');
        $rotated = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotSame($cookie->getValue(), $rotated->getValue());
        $fresh();
        $this->call('GET', '/api/me/devices');
        self::assertSame(200, $this->code(), 'ancien jeton accepté juste après la rotation (onglets simultanés)');
        $this->em()->getConnection()->executeStatement('UPDATE trusted_device SET rotated_at = NOW() - INTERVAL 5 MINUTE');
        $fresh();
        $this->call('GET', '/api/me/devices');
        self::assertSame(401, $this->code(), 'ancien jeton réutilisé plus tard : refusé');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM trusted_device'), 'vol présumé : appareil révoqué');
    }

    public function testRevocationsAndPasswordChange(): void
    {
        $remember = function () {
            $this->client->getCookieJar()->clear();
            $this->call('POST', '/api/login', ['email' => 'admin@test.io', 'password' => 'admin-password-123', '_remember_me' => true]);

            return $this->client->getCookieJar()->get('REMEMBERME')->getValue();
        };
        $a = $remember();
        $b = $remember();
        $c = $remember();
        $list = $this->call('GET', '/api/me/devices');
        self::assertCount(3, $list);

        // Révoquer un autre appareil, puis « tous les autres »
        $other = array_values(array_filter($list, fn ($d) => !$d['current']))[0];
        self::assertCount(2, $this->call('DELETE', "/api/me/devices/{$other['id']}"));
        self::assertCount(1, $this->call('DELETE', '/api/me/devices'));
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', $a));
        $this->call('GET', '/api/me/devices');
        self::assertSame(401, $this->code(), 'appareil révoqué : il faut se reconnecter');

        // Changement de mot de passe : anciens cookies révoqués, cet appareil reste de confiance avec un nouveau cookie
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', $c));
        $this->call('PUT', '/api/me', ['currentPassword' => 'admin-password-123', 'newPassword' => self::NEW]);
        self::assertSame(200, $this->code());
        $after = $this->client->getCookieJar()->get('REMEMBERME')->getValue();
        self::assertNotSame($c, $after);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', $c));
        $this->call('GET', '/api/me/devices');
        self::assertSame(401, $this->code());
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', $after));
        self::assertCount(1, $this->call('GET', '/api/me/devices'));

        // Un autre utilisateur ne peut pas révoquer mes appareils ; désactiver un compte révoque les siens
        $mine = $this->call('GET', '/api/me/devices')[0]['id'];
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->client->getCookieJar()->clear();
        $this->login('ed@test.io', 'ed-password-12345');
        $this->call('DELETE', "/api/me/devices/$mine");
        self::assertSame(404, $this->code());
        $this->call('POST', '/api/logout');
        $this->client->getCookieJar()->clear();
        $this->login('admin@test.io', self::NEW);
        $ed = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'ed@test.io']);
        $this->em()->persist(new TrustedDevice($ed, 'serie-ed', 'x'));
        $this->em()->flush();
        $this->call('PUT', "/api/admin/users/{$ed->id}", ['isActive' => false]);
        self::assertSame(200, $this->code());
        self::assertSame(0, $this->em()->getRepository(TrustedDevice::class)->count(['series' => 'serie-ed']));
    }
}
