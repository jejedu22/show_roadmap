<?php

namespace App\Tests;

use App\Entity\Event;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ImportSqliteTest extends ApiTestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        // Base de l'ancienne version : admin@example.com (déjà présent ici), bob@example.com, old@example.com (désactivé)
        $this->db = sys_get_temp_dir().'/old_app_'.bin2hex(random_bytes(4)).'.db';
        (new \PDO('sqlite:'.$this->db))->exec((string) file_get_contents(__DIR__.'/fixtures/old_app.sql'));
        $this->createUser('admin@example.com', 'current-password-1', true);
    }

    protected function tearDown(): void
    {
        @unlink($this->db);
        parent::tearDown();
    }

    private function import(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:import-sqlite'));
        $tester->execute(['file' => $this->db] + $args);

        return $tester;
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->import(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(Event::class)->count([]));
    }

    public function testImport(): void
    {
        $tester = $this->import([]);
        $tester->assertCommandIsSuccessful();
        $out = $tester->getDisplay();
        self::assertStringContainsString('bob@example.com', $out);
        self::assertStringNotContainsString('old@example.com', $out, 'pas de mot de passe pour un compte désactivé');
        self::assertStringNotContainsString('admin@example.com', $out, 'compte existant : mot de passe conservé');
        preg_match('/bob@example\.com\s+(\S+)/', $out, $m);

        // les montants recalculés sont identiques à ceux de l'ancienne version
        $this->login('admin@example.com', 'current-password-1');
        $companies = array_column($this->call('GET', '/api/companies'), 'id', 'name');
        self::assertSame(['Cie Vide', 'Compagnie Démo'], array_keys($companies));
        $events = $this->call('GET', '/api/companies/'.$companies['Compagnie Démo'])['events'];
        $beauvais = $this->call('GET', '/api/events/'.$events[0]['id']);
        self::assertSame('option', $beauvais['status']);
        self::assertEquals(10713.2, $beauvais['quote']['totalHt']);
        self::assertEquals(11302.43, $beauvais['quote']['totalTtc']);
        self::assertSame(4, $beauvais['teamSize']);
        self::assertSame('Hôtel Cathédrale', $beauvais['lodgings'][0]['name']);
        self::assertSame($beauvais['lodgings'][0]['id'], $beauvais['participations'][0]['lodgingId']);
        self::assertNotEmpty($beauvais['participations'][3]['conflicts'], 'conflit David / Prélude conservé');
        $this->call('POST', '/api/logout');

        // Bob se connecte avec son mot de passe temporaire et voit sa compagnie
        $this->login('bob@example.com', $m[1]);
        self::assertCount(1, $this->call('GET', '/api/companies'));

        // relance refusée : pas de doublons
        self::assertSame(1, $this->import([])->getStatusCode());
    }

    public function testRejectsUnknownFile(): void
    {
        file_put_contents($this->db, '');
        (new \PDO('sqlite:'.$this->db))->exec('CREATE TABLE autre (id INTEGER)');
        self::assertSame(1, $this->import([])->getStatusCode());
    }
}
