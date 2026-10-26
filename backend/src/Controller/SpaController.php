<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Sert l'application Vue (build copié dans public/) pour toute URL hors /api. */
class SpaController extends AbstractController
{
    #[Route('/healthz', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('ok');
    }

    #[Route('/{path}', requirements: ['path' => '(?!api/|api$).*'], methods: ['GET'], priority: -100)]
    public function index(): Response
    {
        $file = $this->getParameter('kernel.project_dir').'/public/index.html';
        if (!is_file($file)) {
            return new Response('Front non construit : lancez « npm run build » dans frontend/.', 503);
        }

        return new Response((string) file_get_contents($file), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
