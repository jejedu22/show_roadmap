<?php

namespace App\Controller;

use App\Entity\Event;
use App\Service\PdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Feuille de route partagée avec l'équipe : accessible sans compte, par un lien non devinable et révocable. */
class ShareController extends AbstractController
{
    #[Route('/partage/{token<[0-9a-f]{64}>}', methods: ['GET'])]
    public function roadmap(string $token, EntityManagerInterface $em, PdfRenderer $renderer): Response
    {
        $event = $em->getRepository(Event::class)->findOneBy(['shareToken' => $token]);
        if (!$event) {
            throw new NotFoundHttpException('Lien expiré ou invalide');
        }
        $name = PdfRenderer::filename($event, 'roadmap');

        return new Response($renderer->render($event, 'roadmap'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, preg_replace('/[^\x20-\x7E]/', '', $name) ?: 'feuille-de-route.pdf'),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
