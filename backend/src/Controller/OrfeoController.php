<?php

namespace App\Controller;

use App\Entity\Company;
use App\Service\Input;
use App\Service\Orfeo\OrfeoException;
use App\Service\Orfeo\OrfeoImporter;
use App\Service\Secrets;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Intégration Orfeo d'une compagnie : clé d'API (paramètres) et récupération des dates (import avec relecture). */
#[Route('/api/companies/{id<\d+>}/orfeo')]
class OrfeoController extends ApiController
{
    /** Enregistre la clé après l'avoir essayée auprès d'Orfeo ; elle n'est jamais renvoyée au navigateur. */
    #[Route('', methods: ['PUT'])]
    public function connect(int $id, Request $request, OrfeoImporter $orfeo, Secrets $secrets): JsonResponse
    {
        $company = $this->company($id, 'company.manage');
        $token = trim(Input::str($this->body($request), 'token', 255));
        if (!preg_match('/^[A-Za-z0-9._\-]{8,255}$/', $token)) {
            throw new BadRequestHttpException('Clé d\'API invalide : copiez-la depuis Orfeo (Paramètres → API & webhooks).');
        }
        $company->orfeoOrganization = $this->orfeo(fn () => $orfeo->organization($token));
        $company->orfeoToken = $secrets->encrypt($token);
        $this->audit->log('orfeo.connect', $company->name, ['organisation' => $company->orfeoOrganization]);

        return $this->savedCompany($company);
    }

    #[Route('', methods: ['DELETE'])]
    public function disconnect(int $id): JsonResponse
    {
        $company = $this->company($id, 'company.manage');
        [$company->orfeoToken, $company->orfeoOrganization] = [null, ''];
        $this->audit->log('orfeo.disconnect', $company->name);

        return $this->savedCompany($company);
    }

    /** Dates Orfeo d'une période, présentées à la relecture ; l'enregistrement passe par /import/{importId}. */
    #[Route('/import', methods: ['POST'])]
    public function import(int $id, Request $request, OrfeoImporter $orfeo): JsonResponse
    {
        $company = $this->company($id, 'events.edit');
        $this->authz->company($this->me(), $company, 'people.edit');
        $d = $this->body($request);
        $from = Input::date($d, 'from') ?? new \DateTimeImmutable('today');
        $to = Input::date($d, 'to') ?? $from->modify('+6 months');

        return $this->json($this->orfeo(fn () => $orfeo->analyze($company, $this->me(), $from, $to)));
    }

    private function orfeo(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (OrfeoException $e) {
            throw new HttpException(401 === $e->status() ? 400 : $e->status(), $e->getMessage(), $e);
        }
    }
}
