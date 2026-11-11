<?php

namespace App\Controller;

use App\Service\CompanyLogos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Logo d'une compagnie : visible avec « Consulter », modifié avec « Paramètres de la compagnie ». */
#[Route('/api/companies/{id<\d+>}/logo')]
class CompanyLogoController extends ApiController
{
    #[Route('', methods: ['GET'])]
    public function show(int $id, CompanyLogos $logos, Request $request): Response
    {
        $company = $this->company($id);
        $png = $logos->png($company) ?? throw new NotFoundHttpException('Pas de logo');
        $response = new Response($png, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'"]);
        $response->setPrivate();
        $response->setMaxAge(86400 * 30); // l'adresse change avec le logo (?v=)
        $response->setEtag(md5($png));
        $response->isNotModified($request);

        return $response;
    }

    #[Route('', methods: ['POST'])]
    public function upload(int $id, Request $request, CompanyLogos $logos): JsonResponse
    {
        $company = $this->company($id, 'company.manage');
        $files = $request->files->all('logo');
        $logos->store($company, $files[0] ?? $request->files->get('logo'));
        $this->audit->log('company.logo', $company->name);

        return $this->savedCompany($company);
    }

    #[Route('', methods: ['DELETE'])]
    public function delete(int $id, CompanyLogos $logos): JsonResponse
    {
        $company = $this->company($id, 'company.manage');
        $logos->remove($company);
        $this->audit->log('company.logo_removed', $company->name);

        return $this->savedCompany($company);
    }
}
