<?php

namespace App\Twig;

use App\Service\Themes;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/** Couleurs du thème actif dans tous les modèles (PDF, e-mails) : {{ theme.violet }}, {{ theme.stripe }}… */
class ThemeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private Themes $themes)
    {
    }

    public function getGlobals(): array
    {
        $c = $this->themes->colors();

        // Fond très clair des lignes alternées, dérivé de la couleur principale
        return ['theme' => $c + ['stripe' => Themes::tint($c['violetSoft'], 0.55)]];
    }
}
