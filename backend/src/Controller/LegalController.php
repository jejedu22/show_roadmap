<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Informations légales de CETTE instance (éditeur, hébergeur, contact RGPD), lues dans l'environnement : chaque
 * exploitant renseigne les siennes. Publiques : affichées sur les pages « Mentions légales » et « Confidentialité ».
 */
class LegalController extends ApiController
{
    #[Route('/api/legal', methods: ['GET'])]
    public function legal(
        #[Autowire(env: 'LEGAL_NAME')] string $name,
        #[Autowire(env: 'LEGAL_FORM')] string $form,
        #[Autowire(env: 'LEGAL_ID')] string $id,
        #[Autowire(env: 'LEGAL_ADDRESS')] string $address,
        #[Autowire(env: 'LEGAL_EMAIL')] string $email,
        #[Autowire(env: 'LEGAL_PHONE')] string $phone,
        #[Autowire(env: 'LEGAL_DIRECTOR')] string $director,
        #[Autowire(env: 'LEGAL_HOST_NAME')] string $hostName,
        #[Autowire(env: 'LEGAL_HOST_ADDRESS')] string $hostAddress,
        #[Autowire(env: 'LEGAL_HOST_PHONE')] string $hostPhone,
        #[Autowire(env: 'LEGAL_PRIVACY_EMAIL')] string $privacyEmail,
        #[Autowire(env: 'LEGAL_SOURCE_URL')] string $sourceUrl,
    ): JsonResponse {
        $clean = fn (string $v) => trim(preg_replace('/\s+/u', ' ', $v));
        $data = [
            'publisher' => ['name' => $clean($name), 'form' => $clean($form), 'id' => $clean($id), 'address' => $clean($address),
                'email' => $clean($email), 'phone' => $clean($phone), 'director' => $clean($director)],
            'host' => ['name' => $clean($hostName), 'address' => $clean($hostAddress), 'phone' => $clean($hostPhone)],
            'privacyEmail' => $clean($privacyEmail) ?: $clean($email),
            // Obligation de la licence AGPL (§ 13) : proposer le code source aux utilisateurs distants
            'sourceUrl' => filter_var($sourceUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $sourceUrl) ? $sourceUrl : '',
        ];
        $data['configured'] = '' !== $data['publisher']['name'] && '' !== $data['publisher']['email'] && '' !== $data['host']['name'];

        return $this->json($data);
    }
}
