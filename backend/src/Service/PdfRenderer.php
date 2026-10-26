<?php

namespace App\Service;

use App\Entity\Event;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** Documents PDF d'une date (feuille de route, devis…), générés côté serveur avec dompdf. */
class PdfRenderer
{
    public const DOCS = [
        'roadmap' => 'Feuille de route',
        'rooming' => 'Rooming-list',
        'perdiem' => 'Défraiements',
        'travelcost' => 'Suivi des frais de voyage',
        'contacts' => 'Coordonnées de l\'équipe',
        'quote' => 'Devis',
    ];

    public function __construct(private Environment $twig, private Presenter $presenter)
    {
    }

    public function render(Event $event, string $doc): string
    {
        $ev = $this->presenter->event($event);
        $html = $this->twig->render("pdf/$doc.html.twig", [
            'ev' => $ev,
            'title' => self::DOCS[$doc],
            'outbound' => self::travel($ev, 'outbound'),
            'inbound' => self::travel($ev, 'return'),
            'lodgingNames' => array_column($ev['lodgings'], 'name', 'id'),
            'schedule' => self::groupBy($ev['schedule'], 'day'),
            'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('d/m/Y à H:i'),
        ]);

        $options = (new Options())
            ->setDefaultFont('DejaVu Sans')
            ->setIsRemoteEnabled(false) // aucune ressource externe chargée pendant le rendu
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'rooming' === $doc && count($ev['dates']) > 7 ? 'landscape' : 'portrait');
        $pdf->addInfo('Title', self::DOCS[$doc].' — '.$ev['showTitle'].' — '.$ev['venue']);
        $pdf->render();

        // Numéro de page en bas à droite
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'bold');
        $canvas->page_text($canvas->get_width() - 70, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.36, 0.18, 1]);

        return (string) $pdf->output();
    }

    public static function filename(Event $event, string $doc): string
    {
        return str_replace([' ', '/', '\''], ['_', '-', ''], self::DOCS[$doc].'_'.$event->show->title.'_'.$event->venue).'.pdf';
    }

    /** Trajets d'un sens, par jour puis heure de départ : [jour => [[participation, trajet], …]]. */
    private static function travel(array $ev, string $direction): array
    {
        $days = [];
        foreach ($ev['participations'] as $p) {
            foreach ($p['legs'] as $l) {
                if ($l['direction'] === $direction) {
                    $days[$l['day']][] = ['p' => $p, 'l' => $l];
                }
            }
        }
        ksort($days);
        foreach ($days as &$items) {
            usort($items, fn ($a, $b) => self::minutes($a['l']['depTime']) <=> self::minutes($b['l']['depTime']));
        }

        return $days;
    }

    private static function minutes(string $t): int
    {
        return preg_match('/^(\d{1,2})\s*[h:]\s*(\d{0,2})/i', $t, $m) ? (int) $m[1] * 60 + (int) ($m[2] ?: 0) : 1440;
    }

    private static function groupBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[$key]][] = $r;
        }

        return $out;
    }
}
