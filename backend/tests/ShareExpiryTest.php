<?php

namespace App\Tests;

use App\Entity\Event;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Liens de partage (feuille de route, coûts) : échéance par défaut, modifiable, lien expiré refusé puis réactivable, purge. */
class ShareExpiryTest extends ApiTestCase
{
    public function testShareLinksExpire(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $first = Event::today()->modify('+10 days');
        $last = $first->modify('+2 days');
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => $first->format('Y-m-d'), 'lastDate' => $last->format('Y-m-d')]);
        $id = $ev['id'];

        // Création : 30 jours après la fin de la date
        $ev = $this->call('POST', "/api/events/$id/share");
        self::assertSame($last->modify('+30 days')->format('Y-m-d'), $ev['share']['expiresOn']);
        self::assertTrue($ev['share']['active']);
        $token = $ev['share']['token'];
        $ev = $this->call('POST', "/api/events/$id/cost-share");
        self::assertSame($last->modify('+30 days')->format('Y-m-d'), $ev['costShare']['expiresOn']);
        $costToken = $ev['costShare']['token'];

        // Échéance modifiable, ni passée ni à plus d'un an après la fin
        $later = $last->modify('+90 days')->format('Y-m-d');
        self::assertSame($later, $this->call('PUT', "/api/events/$id/share", ['expiresOn' => $later])['share']['expiresOn']);
        $this->call('PUT', "/api/events/$id/share", ['expiresOn' => Event::today()->modify('-1 day')->format('Y-m-d')]);
        self::assertSame(400, $this->code());
        $this->call('PUT', "/api/events/$id/share", ['expiresOn' => $last->modify('+2 years')->format('Y-m-d')]);
        self::assertSame(400, $this->code());
        self::assertSame($later, $this->call('GET', "/api/events/$id")['share']['expiresOn'], 'inchangée après un refus');

        // Lien expiré : refusé sans compte, puis réactivé (même adresse) par un nouvel envoi
        $db = $this->em()->getConnection();
        $db->executeStatement('UPDATE event SET share_expires_on = ?, cost_share_expires_on = ? WHERE id = ?', [Event::today()->modify('-1 day')->format('Y-m-d'), Event::today()->format('Y-m-d'), $id]);
        $ev = $this->call('GET', "/api/events/$id");
        self::assertFalse($ev['share']['active']);
        self::assertTrue($ev['costShare']['active'], 'valable jusqu\'à la fin du jour d\'échéance');
        $this->client->request('GET', "/partage/$token");
        self::assertSame(404, $this->code(), 'lien expiré');
        $this->client->request('GET', "/partage/couts/$costToken");
        self::assertSame(200, $this->code());
        $ev = $this->call('POST', "/api/events/$id/share");
        self::assertSame([$token, true], [$ev['share']['token'], $ev['share']['active']]);
        $this->client->request('GET', "/partage/$token");
        self::assertSame(200, $this->code(), 'réactivé');

        // Purge : liens expirés depuis plus de 30 jours effacés, les autres gardés
        $db->executeStatement('UPDATE event SET share_expires_on = ? WHERE id = ?', [Event::today()->modify('-31 days')->format('Y-m-d'), $id]);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:purge'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame([null, $costToken], array_values($db->fetchAssociative('SELECT share_token, cost_share_token FROM event WHERE id = ?', [$id])));
    }
}
