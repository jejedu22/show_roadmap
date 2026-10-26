<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Horaires SNCF open data : import GTFS et recherche de trains, directs ou avec une correspondance. */
class SncfTimetableTest extends ApiTestCase
{
    private const A = 'StopArea:OCE87271007';
    private const B = 'StopArea:OCE87313510';

    /** Petite archive GTFS au format SNCF ; le 18/11/2026 est un mercredi. */
    public static function gtfsZip(): string
    {
        $files = [
            'stops.txt' => "\u{FEFF}stop_id,stop_name,stop_desc,stop_lat,stop_lon,zone_id,stop_url,location_type,parent_station\n"
                ."StopArea:OCE87271007,Paris Gare du Nord,,48.88,2.35,,,1,\n"
                ."StopPoint:OCETrain TER-87271007,Paris Gare du Nord,,48.88,2.35,,,0,StopArea:OCE87271007\n"
                ."StopArea:OCE87313510,Beauvais,,49.42,2.08,,,1,\n"
                ."StopPoint:OCETrain TER-87313510,Beauvais,,49.42,2.08,,,0,StopArea:OCE87313510\n"
                ."StopArea:OCE87726000,Saint-Étienne Châteaucreux,,45.44,4.39,,,1,\n"
                ."StopPoint:OCETGV INOUI-87726000,Saint-Étienne Châteaucreux,,45.44,4.39,,,0,StopArea:OCE87726000\n"
                ."StopArea:OCE99999999,Gare sans train,,0,0,,,1,\n",
            'trips.txt' => "route_id,service_id,trip_id,trip_headsign,direction_id,block_id,shape_id\n"
                ."R,S1,T-JOUR,847407,0,,\n"   // le 18 : 7h37 → 8h51
                ."R,S2,T-NUIT,847999,0,,\n"   // part la veille (17) à 24h30 → 0h30 le 18
                ."R,S1,T-INVERSE,847408,1,,\n" // Beauvais → Paris : ne doit pas sortir
                ."R,S1,T-SANS-MONTEE,847410,0,,\n" // montée interdite à Paris
                ."R,S3,T-SEMAINE,847420,0,,\n"  // calendar.txt : en semaine, sauf le 18 (exception)
                ."R,S1,T-TGV,6602,0,,\n"        // Saint-Étienne → Paris
                ."R,S1,T-SUITE,847500,0,,\n",    // Paris 8h30 → Beauvais : correspondance avec le TGV (arrivé 8h14)
            'stop_times.txt' => "trip_id,arrival_time,departure_time,stop_id,stop_sequence,stop_headsign,pickup_type,drop_off_type,shape_dist_traveled\n"
                ."T-JOUR,07:37:00,07:37:00,StopPoint:OCETrain TER-87271007,0,,0,1,\n"
                ."T-JOUR,08:51:00,08:51:00,StopPoint:OCETrain TER-87313510,1,,1,0,\n"
                ."T-NUIT,24:30:00,24:30:00,StopPoint:OCETrain TER-87271007,0,,0,1,\n"
                ."T-NUIT,25:40:00,25:40:00,StopPoint:OCETrain TER-87313510,1,,1,0,\n"
                ."T-INVERSE,10:00:00,10:00:00,StopPoint:OCETrain TER-87313510,0,,0,1,\n"
                ."T-INVERSE,11:15:00,11:15:00,StopPoint:OCETrain TER-87271007,1,,1,0,\n"
                ."T-SANS-MONTEE,12:00:00,12:00:00,StopPoint:OCETrain TER-87271007,0,,1,0,\n"
                ."T-SANS-MONTEE,13:15:00,13:15:00,StopPoint:OCETrain TER-87313510,1,,1,0,\n"
                ."T-SEMAINE,14:00:00,14:00:00,StopPoint:OCETrain TER-87271007,0,,0,1,\n"
                ."T-SEMAINE,15:15:00,15:15:00,StopPoint:OCETrain TER-87313510,1,,1,0,\n"
                ."T-TGV,06:04:00,06:04:00,StopPoint:OCETGV INOUI-87726000,0,,0,1,\n"
                ."T-TGV,08:14:00,08:14:00,StopPoint:OCETrain TER-87271007,1,,1,0,\n"
                ."T-SUITE,08:30:00,08:30:00,StopPoint:OCETrain TER-87271007,0,,0,1,\n"
                ."T-SUITE,09:45:00,09:45:00,StopPoint:OCETrain TER-87313510,1,,1,0,\n",
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\n"
                ."S3,1,1,1,1,1,0,0,20261116,20261120\n",
            'calendar_dates.txt' => "service_id,date,exception_type\nS1,20261118,1\nS2,20261117,1\nS3,20261118,2\n",
        ];
        $path = tempnam(sys_get_temp_dir(), 'gtfs').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = (string) file_get_contents($path);
        unlink($path);

        return $data;
    }

    private function importCommand(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:gtfs-import'));
        $tester->execute($args);

        return $tester;
    }

    private function importFixture(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gtfs');
        file_put_contents($file, self::gtfsZip());
        $this->importCommand(['--file' => $file])->assertCommandIsSuccessful();
        unlink($file);
    }

    private function event(): int
    {
        $this->login();
        [, [$show]] = $this->buildCompany();

        return $this->call('POST', "/api/shows/$show/events", ['venue' => 'V', 'firstDate' => '2026-11-18'])['id'];
    }

    public function testSearchDirectTrains(): void
    {
        $ev = $this->event();
        self::assertFalse($this->call('GET', '/api/config')['sncf'], 'désactivé tant que rien n\'est importé');
        $this->importFixture();
        self::assertTrue($this->call('GET', '/api/config')['sncf']);

        self::assertSame([['id' => 'StopArea:OCE87726000', 'name' => 'Saint-Étienne Châteaucreux']],
            $this->call('GET', "/api/events/$ev/sncf/places?q=".rawurlencode('saint etienne')), 'mots séparés, sans accents ni tirets');
        self::assertSame('Paris Gare du Nord', $this->call('GET', "/api/events/$ev/sncf/places?q=gare+du+nord")[0]['name']);
        self::assertSame([], $this->call('GET', "/api/events/$ev/sncf/places?q=".rawurlencode('Gare sans')), 'gare sans aucun train ignorée');

        // « Paris » : seule la gare reliée à Beauvais doit être retenue
        $pair = $this->call('GET', "/api/events/$ev/sncf/pair?from=Paris&to=Beauvais");
        self::assertSame(['id' => self::A, 'name' => 'Paris Gare du Nord'], $pair['from']);
        self::assertSame(self::B, $pair['to']['id']);
        self::assertNull($this->call('GET', "/api/events/$ev/sncf/pair?from=Nulle+part&to=Beauvais")['from']);

        $q = fn (string $from, string $to, string $day, string $time = '') => $this->call('GET', "/api/events/$ev/sncf/journeys?".
            http_build_query(['origin' => $from, 'dest' => $to, 'day' => $day, 'time' => $time]));
        $brief = fn (array $list) => array_map(fn ($j) => array_diff_key($j, ['segments' => 1]), $list);
        self::assertSame([
            ['depTime' => '0h30', 'arrTime' => '1h40', 'mode' => 'TER', 'transfers' => 0, 'durationMin' => 70, 'numbers' => '847999'],
            ['depTime' => '7h37', 'arrTime' => '8h51', 'mode' => 'TER', 'transfers' => 0, 'durationMin' => 74, 'numbers' => '847407'],
            ['depTime' => '8h30', 'arrTime' => '9h45', 'mode' => 'TER', 'transfers' => 0, 'durationMin' => 75, 'numbers' => '847500'],
        ], $brief($q(self::A, self::B, '2026-11-18')));
        self::assertSame(['847407', '847500'], array_column($q(self::A, self::B, '2026-11-18', '07:00'), 'numbers'));

        // Pas de train direct Saint-Étienne → Beauvais : TGV jusqu'à Paris, puis TER (16 min de changement)
        $via = $q('StopArea:OCE87726000', self::B, '2026-11-18');
        self::assertSame([['depTime' => '6h04', 'arrTime' => '9h45', 'mode' => 'TGV INOUI + TER', 'transfers' => 1, 'durationMin' => 221, 'numbers' => '6602 + 847500']], $brief($via));
        self::assertSame([['Saint-Étienne Châteaucreux', '6h04', 'Paris Gare du Nord', '8h14'], ['Paris Gare du Nord', '8h30', 'Beauvais', '9h45']],
            array_map(fn ($g) => [$g['from'], $g['depTime'], $g['to'], $g['arrTime']], $via[0]['segments']));
        self::assertSame([], $q('StopArea:OCE87726000', self::B, '2026-11-18', '07:00'), 'plus de TGV après 7h');
        self::assertSame(['847420'], array_column($q(self::A, self::B, '2026-11-19', '13:00'), 'numbers'), 'calendar.txt : jeudi');
        self::assertSame([], $q(self::A, self::B, '2026-11-21'), 'samedi : aucun train');
        self::assertSame('TGV INOUI', $q('StopArea:OCE87726000', self::A, '2026-11-18')[0]['mode']);
        $nuit = $q(self::A, self::B, '2026-11-17', '23:00');
        self::assertSame(['0h30 (+1)', '1h40 (+1)'], [$nuit[0]['depTime'], $nuit[0]['arrTime']], 'train de nuit vu depuis la veille');

        $q(self::A, self::B, 'pas-une-date');
        self::assertSame(400, $this->code());
    }

    public function testDownloadAndFailedImportKeepsData(): void
    {
        $fake = static::getContainer()->get(FakeHttp::class);
        $fake->gtfsZip = self::gtfsZip();
        $tester = $this->importCommand();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('gtfs.test', implode(' ', $fake->calls));
        self::assertStringContainsString('3 gares, 7 trains', $tester->getDisplay());

        // déjà à jour : rien à faire
        self::assertStringContainsString('à jour', $this->importCommand(['--if-older-than' => 20])->getDisplay());

        // archive invalide : échec, les horaires précédents restent en place
        $bad = tempnam(sys_get_temp_dir(), 'gtfs');
        file_put_contents($bad, 'pas une archive');
        self::assertSame(1, $this->importCommand(['--file' => $bad])->getStatusCode());
        unlink($bad);
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM gtfs_stop'));
    }

    public function testConcurrentImportIsSkipped(): void
    {
        // Un autre processus tient le verrou (ex. import lancé au démarrage du conteneur)
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $other = \Doctrine\DBAL\DriverManager::getConnection($db->getParams());
        self::assertSame(1, (int) $other->fetchOne("SELECT GET_LOCK('gtfs_import', 0)"));

        $file = tempnam(sys_get_temp_dir(), 'gtfs');
        file_put_contents($file, self::gtfsZip());
        $tester = $this->importCommand(['--file' => $file]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('déjà en cours', $tester->getDisplay());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM gtfs_stop'), 'aucune table touchée');

        $other->fetchOne("SELECT RELEASE_LOCK('gtfs_import')");
        $other->close();
        $this->importCommand(['--file' => $file])->assertCommandIsSuccessful();
        self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM gtfs_stop'));
        unlink($file);
    }
}
