<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\TrustedDevice;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Informations légales publiques et limitation de la conservation (RGPD). */
class LegalAndPurgeTest extends ApiTestCase
{
    public function testLegalInformationIsPublicAndComesFromEnvironment(): void
    {
        $res = $this->call('GET', '/api/legal'); // sans être connecté
        self::assertSame(200, $this->code());
        self::assertSame(['publisher', 'host', 'privacyEmail', 'sourceUrl', 'configured'], array_keys($res));
        self::assertFalse($res['configured'], 'rien de renseigné : l\'exploitant doit compléter le .env');
        self::assertMatchesRegularExpression('#^https?://#', $res['sourceUrl'], 'lien vers le code source (licence AGPL)');
        foreach (['name', 'form', 'id', 'address', 'email', 'phone', 'director'] as $k) {
            self::assertArrayHasKey($k, $res['publisher']);
        }
    }

    public function testPurgeRemovesOnlyExpiredTechnicalData(): void
    {
        $em = $this->em();
        $old = new \DateTimeImmutable('-13 months');
        foreach ([[$old, 'vieux'], [new \DateTimeImmutable('-2 months'), 'recent']] as [$at, $action]) {
            $a = new AuditLog();
            [$a->action, $a->actor] = [$action, 'x@test.io'];
            $em->persist($a);
            $em->flush();
            $em->getConnection()->executeStatement('UPDATE audit_log SET at = ? WHERE id = ?', [$at->format('Y-m-d H:i:s'), $a->id]);
        }
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'admin@test.io']);
        foreach (['ancien' => '-40 days', 'actif' => '-2 days'] as $series => $ago) {
            $d = new TrustedDevice($admin, $series, 'h');
            $d->lastUsed = new \DateTimeImmutable($ago);
            $em->persist($d);
        }
        $em->flush();
        $em->getConnection()->executeStatement("INSERT INTO user_token (type, hash, expires_at, used_at, user_id) VALUES ('reset', 'h1', '2020-01-01 00:00:00', NULL, ?), ('reset', 'h2', '2099-01-01 00:00:00', NULL, ?)", [$admin->id, $admin->id]);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:purge'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $db = $em->getConnection();
        self::assertSame(['recent'], $db->fetchFirstColumn("SELECT action FROM audit_log WHERE action IN ('vieux','recent')"));
        self::assertSame(['actif'], $db->fetchFirstColumn('SELECT series FROM trusted_device'));
        self::assertSame(['h2'], $db->fetchFirstColumn('SELECT hash FROM user_token'));
    }
}
