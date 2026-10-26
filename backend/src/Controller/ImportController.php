<?php

namespace App\Controller;

use App\Entity\Company;
use App\Service\Import\TourImporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Import des classeurs Excel « Devis_<Spectacle>_<Ville>.xlsx » : analyse puis enregistrement. */
class ImportController extends ApiController
{
    #[Route('/api/companies/{id<\d+>}/import', methods: ['POST'])]
    public function analyze(int $id, Request $request, TourImporter $importer): JsonResponse
    {
        $company = $this->allowed($id);
        $files = $request->files->all('files');

        return $this->json($importer->analyze($company, $this->me(), array_values(array_filter($files))));
    }

    #[Route('/api/companies/{id<\d+>}/import/{importId<[a-f0-9]{24}>}', methods: ['POST'])]
    public function commit(int $id, string $importId, Request $request, TourImporter $importer): JsonResponse
    {
        $company = $this->allowed($id);
        $choices = $this->body($request)['files'] ?? null;
        if (!is_array($choices)) {
            throw new BadRequestHttpException('Aucun fichier à importer.');
        }

        return $this->json($importer->commit($company, $this->me(), $importId, $choices, (array) ($this->body($request)['vocabulary'] ?? [])));
    }

    /** Importer crée des dates et des fiches de personnes : il faut les deux droits. */
    private function allowed(int $id): Company
    {
        $company = $this->company($id, 'events.edit');
        $this->authz->company($this->me(), $company, 'people.edit');

        return $company;
    }
}
