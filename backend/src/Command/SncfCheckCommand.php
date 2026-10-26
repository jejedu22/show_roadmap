<?php

namespace App\Command;

use App\Service\SncfClient;
use App\Service\SncfException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Diagnostic de l'accès à l'API SNCF depuis le serveur : jeton, réseau, réponse. */
#[AsCommand('app:sncf-check', "Teste l'accès à l'API SNCF et explique le problème éventuel")]
class SncfCheckCommand extends Command
{
    public function __construct(
        private HttpClientInterface $http,
        private SncfClient $sncf,
        #[Autowire(env: 'SNCF_API_TOKEN')] private string $token,
        #[Autowire(env: 'SNCF_API_URL')] private string $baseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $token = trim($this->token);
        if ('' === $token) {
            $io->error("SNCF_API_TOKEN est vide dans le conteneur. Renseignez-le dans .env puis recréez le conteneur : docker compose up -d (un simple « restart » ne relit pas .env).");

            return Command::FAILURE;
        }
        if ($token !== $this->token) {
            $io->warning('Le jeton contient des espaces ou un retour à la ligne en trop : retirez-les dans .env.');
        }
        $io->writeln(sprintf('Jeton : %s… (%d caractères)', substr($token, 0, 4), strlen($token)));
        $io->writeln('URL : '.$this->baseUrl);

        try {
            $res = $this->http->request('GET', rtrim($this->baseUrl, '/').'/places', [
                'query' => ['q' => 'Paris', 'type[]' => 'stop_area', 'count' => 3], 'auth_basic' => [$this->token, ''],
            ]);
            $status = $res->getStatusCode();
            $body = $res->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $io->error("Le serveur n'arrive pas à joindre l'API SNCF : ".$e->getMessage()."\nVérifiez l'accès Internet sortant du conteneur (pare-feu, proxy, DNS).");

            return Command::FAILURE;
        }
        $io->writeln("Réponse HTTP : $status");
        if (401 === $status || 403 === $status) {
            $io->error("Jeton refusé par la SNCF. Vérifiez qu'il est copié en entier, sans guillemets, et qu'il a bien été activé (lien reçu par e-mail).\nRéponse : ".mb_substr($body, 0, 300));

            return Command::FAILURE;
        }
        if ($status >= 400) {
            $io->error('Erreur de l\'API SNCF : '.mb_substr($body, 0, 300));

            return Command::FAILURE;
        }
        try {
            $places = $this->sncf->places('Paris');
        } catch (SncfException $e) {
            $io->error('Réponse inattendue : '.$e->getMessage());

            return Command::FAILURE;
        }
        if (!$places) {
            $io->warning("L'API répond mais ne renvoie aucune gare pour « Paris ». Réponse : ".mb_substr($body, 0, 300));

            return Command::FAILURE;
        }
        $io->success('API SNCF joignable. Exemples : '.implode(', ', array_column($places, 'name')));

        return Command::SUCCESS;
    }
}
