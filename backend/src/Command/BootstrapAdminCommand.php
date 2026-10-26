<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand('app:bootstrap-admin', 'Crée le compte administrateur (ADMIN_EMAIL / ADMIN_PASSWORD) si la base ne contient aucun utilisateur')]
class BootstrapAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire(env: 'ADMIN_EMAIL')] private string $email,
        #[Autowire(env: 'ADMIN_PASSWORD')] private string $password,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->em->getRepository(User::class)->count([]) > 0) {
            return Command::SUCCESS;
        }
        if ('' === $this->email || mb_strlen($this->password) < 10) {
            $output->writeln('<comment>Aucun utilisateur : définissez ADMIN_EMAIL et ADMIN_PASSWORD (10 caractères min.).</comment>');

            return Command::SUCCESS;
        }
        $user = new User();
        $user->email = mb_strtolower($this->email);
        $user->name = 'Administrateur';
        $user->isAdmin = true;
        $user->password = $this->hasher->hashPassword($user, $this->password);
        $this->em->persist($user);
        $this->em->flush();
        $output->writeln("Administrateur créé : {$user->email}");

        return Command::SUCCESS;
    }
}
