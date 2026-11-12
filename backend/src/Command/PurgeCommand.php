<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Limitation de la conservation (RGPD) : supprime le journal d'audit ancien, les liens d'invitation ou de
 * réinitialisation périmés, les liens de partage expirés et les appareils de confiance inutilisés. Lancée chaque nuit par le conteneur.
 */
#[AsCommand('app:purge', 'Supprime les données techniques périmées (journal, liens, partages expirés, appareils de confiance)')]
class PurgeCommand extends Command
{
    public const AUDIT_MONTHS = 12;
    public const TOKEN_DAYS = 30;
    public const DEVICE_DAYS = 30;

    public function __construct(private Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('audit-months', null, InputOption::VALUE_REQUIRED, 'Durée de conservation du journal d\'audit, en mois', (string) self::AUDIT_MONTHS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) $input->getOption('audit-months'));
        $deleted = [
            'journal d\'audit' => $this->db->executeStatement('DELETE FROM audit_log WHERE at < ?', [(new \DateTimeImmutable("-$months months"))->format('Y-m-d H:i:s')]),
            'liens périmés' => $this->db->executeStatement('DELETE FROM user_token WHERE expires_at < ? OR used_at < ?', array_fill(0, 2, (new \DateTimeImmutable('-'.self::TOKEN_DAYS.' days'))->format('Y-m-d H:i:s'))),
            // Liens de partage expirés depuis plus de 30 jours : effacés (l'adresse ne pourra plus jamais servir)
            'liens de partage expirés' => $this->db->executeStatement('UPDATE event SET share_token = NULL, shared_at = NULL, share_expires_on = NULL WHERE share_expires_on < ?', [$old = (new \DateTimeImmutable('-'.self::TOKEN_DAYS.' days'))->format('Y-m-d')])
                + $this->db->executeStatement('UPDATE event SET cost_share_token = NULL, cost_shared_at = NULL, cost_share_expires_on = NULL WHERE cost_share_expires_on < ?', [$old]),
            'appareils inutilisés' => $this->db->executeStatement('DELETE FROM trusted_device WHERE last_used < ?', [(new \DateTimeImmutable('-'.self::DEVICE_DAYS.' days'))->format('Y-m-d H:i:s')]),
        ];
        $io->writeln(implode(', ', array_map(fn ($label, $n) => "$label : $n", array_keys($deleted), $deleted)));
        $io->success(sprintf('Purge terminée (%d ligne(s) supprimée(s)).', array_sum($deleted)));

        return Command::SUCCESS;
    }
}
