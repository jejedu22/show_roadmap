<?php

namespace App\Service;

use App\Entity\AppSetting;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thèmes de couleurs : chaque compagnie choisit le sien (Paramètres), pour reconnaître d'un coup d'œil sur quelle compagnie
 * on travaille ; le thème par défaut (Administration → Apparence) vaut pour la connexion, l'administration et les compagnies
 * sans thème. Ils habillent l'application, les PDF et les exports Excel.
 * Chaque thème garde les mêmes rôles (couleur principale, accent, surlignage, réussite, encre, fonds) ; les contrastes
 * de lecture (texte blanc sur la couleur principale, texte foncé sur les fonds clairs…) respectent le niveau AA.
 */
class Themes
{
    public const DEFAULT = 'violet';

    /** Rôles : violet = principale, coral = accent et alertes, sun = surlignage, mint = réussite, ink = encre et fonds sombres. */
    public const THEMES = [
        'violet' => ['name' => 'Violet', 'description' => 'Violet, corail, jaune et menthe — le thème d\'origine', 'colors' => [
            'ink' => '#1b1b3a', 'ink2' => '#26244a', 'ink3' => '#3a3766',
            'violet' => '#5b2eff', 'violetDark' => '#3a16c9', 'violetSoft' => '#efeaff', 'violetMid' => '#7a57ff',
            'coral' => '#ff5a5f', 'coralDark' => '#b3171d', 'coralSoft' => '#fff0f0',
            'sun' => '#ffc93c', 'sunSoft' => '#fff3d1', 'sunDark' => '#7a4f00',
            'mint' => '#00c2a8', 'mintSoft' => '#d8f6f1', 'mintDark' => '#00695c',
            'bg' => '#f7f5ff', 'line' => '#e2def5', 'line2' => '#d6d1f0', 'muted' => '#5e5a78', 'mutedOnDark' => '#b9b4da',
        ]],
        'ocean' => ['name' => 'Océan', 'description' => 'Bleu franc, orangé et turquoise', 'colors' => [
            'ink' => '#10223d', 'ink2' => '#182f52', 'ink3' => '#2b4670',
            'violet' => '#1d5fd6', 'violetDark' => '#123f95', 'violetSoft' => '#e8f0ff', 'violetMid' => '#4a80e3',
            'coral' => '#f2603f', 'coralDark' => '#a8321a', 'coralSoft' => '#fff1ec',
            'sun' => '#ffc43d', 'sunSoft' => '#fff2d0', 'sunDark' => '#744c00',
            'mint' => '#11b3a3', 'mintSoft' => '#d6f4f0', 'mintDark' => '#00675d',
            'bg' => '#f3f7fd', 'line' => '#dae4f3', 'line2' => '#cbd8ee', 'muted' => '#526079', 'mutedOnDark' => '#aebcd8',
        ]],
        'foret' => ['name' => 'Forêt', 'description' => 'Vert sapin, corail et sable', 'colors' => [
            'ink' => '#14261d', 'ink2' => '#1d3529', 'ink3' => '#2e4d3c',
            'violet' => '#1e7a4c', 'violetDark' => '#135233', 'violetSoft' => '#e5f3eb', 'violetMid' => '#3f9b6b',
            'coral' => '#e8654a', 'coralDark' => '#a03520', 'coralSoft' => '#fff0eb',
            'sun' => '#f2bf47', 'sunSoft' => '#fcf1d2', 'sunDark' => '#6c4c00',
            'mint' => '#2a9d8f', 'mintSoft' => '#dbf1ee', 'mintDark' => '#1b665d',
            'bg' => '#f4f8f4', 'line' => '#dbe6dd', 'line2' => '#ccdad0', 'muted' => '#53645a', 'mutedOnDark' => '#afc4b6',
        ]],
        'prune' => ['name' => 'Prune', 'description' => 'Prune, corail et or', 'colors' => [
            'ink' => '#2a1530', 'ink2' => '#371d3f', 'ink3' => '#4f2e59',
            'violet' => '#8b2c74', 'violetDark' => '#5f1a4f', 'violetSoft' => '#f6e8f2', 'violetMid' => '#a64e8f',
            'coral' => '#ff6b5e', 'coralDark' => '#b02418', 'coralSoft' => '#fff1ef',
            'sun' => '#ffc94a', 'sunSoft' => '#fff3d3', 'sunDark' => '#774f00',
            'mint' => '#1fae8c', 'mintSoft' => '#d8f3ea', 'mintDark' => '#0b6650',
            'bg' => '#fbf6fa', 'line' => '#ecdfe9', 'line2' => '#e0cfdc', 'muted' => '#655a68', 'mutedOnDark' => '#c4b2c6',
        ]],
        'graphite' => ['name' => 'Graphite', 'description' => 'Bleu ardoise et gris', 'colors' => [
            'ink' => '#1c232c', 'ink2' => '#262f3a', 'ink3' => '#3a4654',
            'violet' => '#2f4b6e', 'violetDark' => '#1d3250', 'violetSoft' => '#e9eef4', 'violetMid' => '#4f6a8e',
            'coral' => '#e8635e', 'coralDark' => '#a02a27', 'coralSoft' => '#fdeeee',
            'sun' => '#e8b84a', 'sunSoft' => '#faf0d6', 'sunDark' => '#6a4b00',
            'mint' => '#2e8b6e', 'mintSoft' => '#dff1ea', 'mintDark' => '#1c5c48',
            'bg' => '#f5f6f8', 'line' => '#e0e4e9', 'line2' => '#d2d8df', 'muted' => '#59626e', 'mutedOnDark' => '#b3bcc7',
        ]],
        'sobre' => ['name' => 'Sobre', 'description' => 'Gris anthracite et blanc, couleurs discrètes', 'colors' => [
            'ink' => '#202327', 'ink2' => '#2b2f34', 'ink3' => '#40454c',
            'violet' => '#3c4148', 'violetDark' => '#25292e', 'violetSoft' => '#eef0f2', 'violetMid' => '#5f656d',
            'coral' => '#e2867d', 'coralDark' => '#9c3a32', 'coralSoft' => '#f9eeed',
            'sun' => '#e3d7c1', 'sunSoft' => '#f5f1e8', 'sunDark' => '#5c4f37',
            'mint' => '#8fbfa6', 'mintSoft' => '#e8f2ec', 'mintDark' => '#2f5c45',
            'bg' => '#f6f6f5', 'line' => '#e3e3e1', 'line2' => '#d5d5d2', 'muted' => '#5d6166', 'mutedOnDark' => '#b6babf',
        ]],
    ];

    private ?string $current = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function currentKey(): string
    {
        if (null === $this->current) {
            $key = $this->em->find(AppSetting::class, 'theme')?->value;
            $this->current = isset(self::THEMES[$key]) ? $key : self::DEFAULT;
        }

        return $this->current;
    }

    /** @return array<string, string> couleurs du thème actif (#rrggbb) */
    public function colors(): array
    {
        return self::THEMES[$this->currentKey()]['colors'];
    }

    /** Clé du thème d'une compagnie : le sien, sinon le thème par défaut. */
    public function keyFor(?Company $company): string
    {
        return $company && isset(self::THEMES[$company->theme]) ? $company->theme : $this->currentKey();
    }

    /** @return array<string, string> couleurs d'une compagnie (documents PDF et Excel de ses dates) */
    public function colorsFor(?Company $company): array
    {
        return self::THEMES[$this->keyFor($company)]['colors'];
    }

    /** Couleurs pour les modèles Twig : celles du thème, plus le fond très clair des lignes alternées. */
    public static function forTemplates(array $colors): array
    {
        return $colors + ['stripe' => self::tint($colors['violetSoft'], 0.55)];
    }

    /** @return array<string, array{name: string, description: string, colors: array<string, string>}> catalogue envoyé à l'interface */
    public static function catalog(): array
    {
        return self::THEMES;
    }

    public function set(string $key): void
    {
        if (!isset(self::THEMES[$key])) {
            throw new \InvalidArgumentException('Thème inconnu');
        }
        $setting = $this->em->find(AppSetting::class, 'theme') ?? new AppSetting('theme');
        $setting->value = $key;
        $this->em->persist($setting);
        $this->em->flush();
        $this->current = $key;
    }

    /** @return array{key: string, colors: array<string, string>} pour l'interface */
    public function active(): array
    {
        return ['key' => $this->currentKey(), 'colors' => $this->colors()];
    }

    /** Mélange une couleur avec du blanc ($ratio = part de blanc) : fonds clairs dérivés (lignes alternées, bordures). */
    public static function tint(string $hex, float $ratio): string
    {
        $c = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => (int) round($v + (255 - $v) * $ratio), $c));
    }
}
