<?php

namespace App\Tests;

use App\Service\Themes;
use App\Twig\FormatExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Feuille de route PDF : coordonnées de l'équipe sur une dernière page, masquée sans téléphone ni e-mail (droit annuaire absent). */
class RoadmapContactsTest extends TestCase
{
    private function html(array $person): string
    {
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../templates'));
        $twig->addExtension(new FormatExtension());
        $ev = ['showTitle' => 'Spectacle', 'venue' => 'Salle', 'city' => '', 'companyName' => 'Cie', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20',
            'performances' => 1, 'address' => '', 'venueContact' => '', 'lodgings' => [], 'sections' => [], 'schedule' => [],
            'participations' => [['person' => $person + ['firstName' => 'Anna', 'lastName' => 'DURAND', 'fullName' => 'DURAND Anna', 'diet' => 'Végétarienne'], 'role' => 'Danseuse', 'legs' => [], 'lodgingId' => null]]];

        return $twig->render('pdf/roadmap.html.twig', ['ev' => $ev, 'title' => 'Feuille de route', 'outbound' => [], 'inbound' => [], 'lodgingNames' => [],
            'schedule' => [], 'weather' => null, 'logo' => null, 'theme' => Themes::forTemplates(Themes::THEMES['violet']['colors']), 'generatedAt' => 'test']);
    }

    public function testContactsPage(): void
    {
        $html = $this->html(['phone' => '06 12 34 56 78', 'email' => 'anna@exemple.fr']);
        self::assertStringContainsString('Coordonnées de l\'équipe', $html);
        self::assertStringContainsString('06 12 34 56 78', $html);
        self::assertStringContainsString('mailto:anna@exemple.fr', $html);
        self::assertStringNotContainsString('Végétarienne', $html, 'pas de régime alimentaire sur la feuille de route');

        self::assertStringNotContainsString('Coordonnées de l\'équipe', $this->html(['phone' => '', 'email' => '']), 'masquée sans coordonnées');
    }
}
