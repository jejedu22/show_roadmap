<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Service\Themes;

/** Thèmes de couleurs : par défaut (administration) et par compagnie, appliqués à l'interface, aux PDF et aux exports Excel. */
class ThemeTest extends ApiTestCase
{
    public function testThemeChoiceAppliesEverywhere(): void
    {
        self::assertSame('violet', $this->call('GET', '/api/config')['theme']['key'], 'thème par défaut, aussi avant connexion');

        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->login('ed@test.io', 'ed-password-12345');
        $this->call('GET', '/api/admin/themes');
        self::assertSame(403, $this->code());
        $this->call('PUT', '/api/admin/theme', ['key' => 'foret']);
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/logout');

        $this->login();
        self::assertCount(count(Themes::THEMES), $this->call('GET', '/api/admin/themes')['themes']);
        $this->call('PUT', '/api/admin/theme', ['key' => 'inconnu']);
        self::assertSame(400, $this->code());
        self::assertSame('foret', $this->call('PUT', '/api/admin/theme', ['key' => 'foret'])['current']);
        $config = $this->call('GET', '/api/config');
        self::assertSame(['foret', Themes::THEMES['foret']['colors']['violet']], [$config['theme']['key'], $config['theme']['colors']['violet']]);
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'settings.theme']));

        self::assertArrayHasKey('prune', $config['themes'], 'catalogue des thèmes pour l\'interface');

        // Thème propre à une compagnie : ses exports et ses PDF le prennent ; sans thème, le thème par défaut
        [$cid, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        self::assertSame(Themes::THEMES['foret']['colors']['violet'], $this->headerColor($ev['id']), 'compagnie sans thème : thème par défaut');
        $this->call('PUT', "/api/companies/$cid/theme", ['theme' => 'inconnu']);
        self::assertSame(400, $this->code());
        self::assertSame('ocean', $this->call('PUT', "/api/companies/$cid/theme", ['theme' => 'ocean'])['theme']);
        self::assertSame('ocean', array_column($this->call('GET', '/api/companies'), null, 'id')[$cid]['theme']);
        self::assertSame(Themes::THEMES['ocean']['colors']['violet'], $this->headerColor($ev['id']), 'export aux couleurs de la compagnie');
        $this->client->request('GET', "/api/events/{$ev['id']}/pdf/roadmap");
        self::assertSame(200, $this->code());
        self::assertSame(1, $this->em()->getRepository(AuditLog::class)->count(['action' => 'company.theme']));
        self::assertNull($this->call('PUT', "/api/companies/$cid/theme", ['theme' => null])['theme']);
        self::assertSame(Themes::THEMES['foret']['colors']['violet'], $this->headerColor($ev['id']), 'retour au thème par défaut');

        // Choisir le thème d'une compagnie demande « Paramètres de la compagnie »
        $this->call('POST', '/api/logout');
        $this->grant(['ed@test.io'], ['company.view'], [$cid]);
        $this->login('ed@test.io', 'ed-password-12345');
        $this->call('PUT', "/api/companies/$cid/theme", ['theme' => 'prune']);
        self::assertSame(403, $this->code());
    }

    /** Couleur de l'en-tête du devis dans l'export des coûts d'une date (#rrggbb). */
    private function headerColor(int $eventId): string
    {
        $this->client->request('GET', "/api/events/$eventId/costs.xlsx");
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getSheetByName('Devis');
        unlink($file);

        return '#'.strtolower($ws->getStyle('A4')->getFill()->getStartColor()->getRGB());
    }

    public function testThemesAreReadable(): void
    {
        // Contrastes de lecture (WCAG AA, 4,5:1) des associations utilisées par l'interface, les PDF et les exports
        $lum = function (string $hex): float {
            $c = array_map(fn ($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, array_map('hexdec', str_split(ltrim($hex, '#'), 2)));

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $ratio = fn ($a, $b) => (max($lum($a), $lum($b)) + 0.05) / (min($lum($a), $lum($b)) + 0.05);
        foreach (Themes::THEMES as $key => $t) {
            $c = $t['colors'] + ['white' => '#ffffff'];
            foreach ([['white', 'violet'], ['white', 'violetDark'], ['white', 'ink'], ['ink', 'sun'], ['ink', 'coral'], ['violetDark', 'violetSoft'],
                ['coralDark', 'coralSoft'], ['mintDark', 'mintSoft'], ['sunDark', 'sunSoft'], ['muted', 'bg'], ['muted', 'white'], ['mutedOnDark', 'ink']] as [$fg, $bg]) {
                self::assertGreaterThanOrEqual(4.5, $ratio($c[$fg], $c[$bg]), "$key : $fg sur $bg");
            }
        }
    }
}
