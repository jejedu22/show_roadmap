<?php

namespace App\Twig;

use App\Service\Themes;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Couleurs du thème par défaut dans tous les modèles (e-mails…) : {{ theme.violet }}, {{ theme.stripe }}…
 * Les PDF d'une date reçoivent à la place celles de sa compagnie (PdfRenderer).
 */
class ThemeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private Themes $themes)
    {
    }

    public function getGlobals(): array
    {
        return ['theme' => Themes::forTemplates($this->themes->colors())];
    }
}
