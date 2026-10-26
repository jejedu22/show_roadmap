<?php

namespace App\Command;

use App\Service\GtfsImporter;
use App\Service\GtfsImportInProgress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('app:gtfs-import', 'Télécharge et charge les horaires SNCF (open data GTFS)')]
class GtfsImportCommand extends Command
{
    public function __construct(
        private GtfsImporter $importer,
        private HttpClientInterface $http,
        #[Autowire(env: 'GTFS_URL')] private string $url,
        /** Horaires complémentaires (Transilien et RER : gares d'Île-de-France absentes du fichier national) ; vide = désactivé */
        #[Autowire(env: 'default::GTFS_IDF_URL')] private ?string $idfUrl = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Archive GTFS locale (au lieu de la télécharger) ; répétable : fichier national puis complémentaires')
            ->addOption('if-older-than', null, InputOption::VALUE_REQUIRED, 'Ne fait rien si le dernier import a moins de N heures');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = $input->getOption('if-older-than');
        $last = $this->importer->lastImport();
        if (null !== $hours && $last && $last > new \DateTimeImmutable("-$hours hours")) {
            $io->writeln('Horaires SNCF à jour (import du '.$last->format('d/m/Y H:i').').');

            return Command::SUCCESS;
        }

        $files = $input->getOption('file');
        $temps = [];
        try {
            if (!$files) {
                foreach (array_filter([$this->url, $this->idfUrl]) as $url) {
                    $io->writeln("Téléchargement de $url");
                    $files[] = $temps[] = $this->download($url);
                }
            }
            $started = microtime(true);
            $stats = $this->importer->import($files);
        } catch (GtfsImportInProgress $e) {
            $io->note($e->getMessage().' Les horaires seront disponibles à la fin de cet import (environ une minute).');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Import des horaires SNCF impossible (les horaires précédents restent en place) : '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            array_map(fn ($f) => @unlink($f), $temps);
        }
        $io->success(sprintf('%d gares, %d trains, %d horaires de passage, du %s au %s (%.0f s).', $stats['stations'], $stats['trips'],
            $stats['stopTimes'], $stats['firstDay'], $stats['lastDay'], microtime(true) - $started));

        return Command::SUCCESS;
    }

    private function download(string $url): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gtfs');
        $response = $this->http->request('GET', $url, ['timeout' => 60, 'max_duration' => 600]);
        $out = fopen($file, 'w');
        try {
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($out, $chunk->getContent());
            }
        } finally {
            fclose($out);
        }
        if (200 !== $response->getStatusCode()) {
            @unlink($file);
            throw new \RuntimeException("Téléchargement refusé (HTTP {$response->getStatusCode()}) : $url");
        }

        return $file;
    }
}
