<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\Day;
use App\Entity\Event;
use App\Entity\ExtraCost;
use App\Entity\Leg;
use App\Entity\Lodging;
use App\Entity\Participation;
use App\Entity\Person;
use App\Entity\RoadmapSection;
use App\Entity\ScheduleItem;
use App\Entity\Show;
use App\Entity\TruckLeg;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Reprend les données de l'ancienne version (FastAPI + SQLite) dans la base MariaDB.
 *
 * Les mots de passe de l'ancienne version (scrypt) ne sont pas vérifiables par Symfony :
 * un compte déjà présent (même e-mail) garde son mot de passe actuel, les autres reçoivent
 * un mot de passe temporaire affiché à la fin.
 */
#[AsCommand('app:import-sqlite', "Importe la base SQLite de l'ancienne version")]
class ImportSqliteCommand extends Command
{
    private \PDO $src;

    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier app.db')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Vérifie l\'import puis annule tout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        if (!is_file($file)) {
            $io->error("Fichier introuvable : $file");

            return Command::FAILURE;
        }
        if ($this->em->getRepository(Company::class)->count([]) > 0) {
            $io->error('La base contient déjà des compagnies : l\'import se fait sur une base vide (seuls des comptes utilisateurs peuvent exister).');

            return Command::FAILURE;
        }
        $this->src = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $missing = array_diff(['users', 'companies', 'shows', 'people', 'events', 'participations', 'days', 'lodgings', 'legs',
            'truck_legs', 'extra_costs', 'roadmap_sections', 'schedule_items', 'user_company'],
            $this->src->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN));
        if ($missing) {
            $io->error('Ce fichier ne ressemble pas à une base de l\'ancienne version (tables manquantes : '.implode(', ', $missing).').');

            return Command::FAILURE;
        }

        $passwords = [];
        $counts = [];
        $this->em->beginTransaction();
        try {
            $users = $this->importUsers($passwords);
            $companies = $this->map('companies', function (array $r) {
                $c = new Company($r['name']);
                $c->notes = (string) $r['notes'];

                return $c;
            });
            foreach ($this->rows('user_company') as $r) {
                if (isset($users[$r['user_id']], $companies[$r['company_id']]) && !$users[$r['user_id']]->companies->contains($companies[$r['company_id']])) {
                    $users[$r['user_id']]->companies->add($companies[$r['company_id']]);
                }
            }
            $shows = $this->map('shows', function (array $r) use ($companies) {
                $s = new Show($companies[$r['company_id']], $r['title']);
                $s->description = (string) $r['description'];

                return $s;
            });
            $people = $this->map('people', function (array $r) use ($companies) {
                $p = new Person($companies[$r['company_id']]);
                foreach (['lastName' => 'last_name', 'firstName' => 'first_name', 'function' => 'function', 'phone' => 'phone',
                    'email' => 'email', 'diet' => 'diet', 'homeCity' => 'home_city'] as $prop => $col) {
                    $p->$prop = (string) $r[$col];
                }

                return $p;
            });
            $events = $this->map('events', function (array $r) use ($shows) {
                $e = new Event($shows[$r['show_id']], $r['venue'], self::date($r['first_date']), self::date($r['last_date']));
                [$e->city, $e->address, $e->venueContact, $e->notes] = [(string) $r['city'], (string) $r['address'], (string) $r['venue_contact'], (string) $r['notes']];
                $e->status = in_array($r['status'], Event::STATUSES, true) ? $r['status'] : 'confirmed';
                [$e->techArrival, $e->artisticArrival, $e->departure] = [self::date($r['tech_arrival']), self::date($r['artistic_arrival']), self::date($r['departure'])];
                $e->performances = max(1, (int) $r['performances']);
                foreach (['mealRate' => 'meal_rate', 'breakfastRate' => 'breakfast_rate', 'nightRate' => 'night_rate', 'truckRate' => 'truck_rate',
                    'vatRate' => 'vat_rate', 'fee' => 'fee', 'lighting' => 'lighting', 'posters' => 'posters'] as $prop => $col) {
                    $e->$prop = self::dec($r[$col]);
                }

                return $e;
            });
            $lodgings = $this->map('lodgings', function (array $r) use ($events) {
                $l = new Lodging($events[$r['event_id']]);
                [$l->name, $l->address, $l->phone, $l->details] = [$r['name'], (string) $r['address'], (string) $r['phone'], (string) $r['details']];

                return $l;
            });
            $parts = $this->map('participations', function (array $r) use ($events, $people, $lodgings) {
                $p = new Participation($events[$r['event_id']], $people[$r['person_id']]);
                [$p->role, $p->roomType, $p->departureCity, $p->note] = [(string) $r['role'], $r['room_type'] ?: 'single', (string) $r['departure_city'], (string) $r['note']];
                $p->lodging = null !== $r['lodging_id'] ? ($lodgings[$r['lodging_id']] ?? null) : null;

                return $p;
            });
            $this->map('days', fn (array $r) => new Day($parts[$r['participation_id']], self::date($r['day']), (int) $r['night'], (int) $r['meals'], (int) $r['breakfast']));
            $this->map('legs', function (array $r) use ($parts) {
                $l = new Leg($parts[$r['participation_id']], self::date($r['day']));
                $l->direction = 'return' === $r['direction'] ? 'return' : 'outbound';
                foreach (['origin' => 'origin', 'destination' => 'destination', 'depTime' => 'dep_time', 'arrTime' => 'arr_time',
                    'mode' => 'mode', 'reference' => 'reference', 'note' => 'note'] as $prop => $col) {
                    $l->$prop = (string) $r[$col];
                }
                $l->price = self::dec($r['price']);
                $l->bookedOn = self::date($r['booked_on']);

                return $l;
            });
            $this->map('truck_legs', function (array $r) use ($events) {
                $t = new TruckLeg($events[$r['event_id']]);
                [$t->label, $t->km] = [(string) $r['label'], self::dec($r['km'], 1)];

                return $t;
            });
            $this->map('extra_costs', function (array $r) use ($events) {
                $x = new ExtraCost($events[$r['event_id']]);
                [$x->label, $x->amount, $x->kind] = [$r['label'], self::dec($r['amount']), 'team_transport' === $r['kind'] ? 'team_transport' : 'other'];

                return $x;
            });
            $this->map('roadmap_sections', function (array $r) use ($events) {
                $s = new RoadmapSection($events[$r['event_id']]);
                [$s->title, $s->body, $s->position] = [$r['title'], (string) $r['body'], (int) $r['position']];

                return $s;
            });
            $this->map('schedule_items', function (array $r) use ($events) {
                $s = new ScheduleItem($events[$r['event_id']]);
                [$s->day, $s->time, $s->label] = [self::date($r['day']), (string) $r['time'], $r['label']];

                return $s;
            });
            $this->em->flush();
            foreach (['users', 'companies', 'shows', 'people', 'events', 'participations', 'days', 'lodgings', 'legs', 'truck_legs',
                'extra_costs', 'roadmap_sections', 'schedule_items'] as $t) {
                $counts[] = [$t, (int) $this->src->query("SELECT COUNT(*) FROM $t")->fetchColumn()];
            }
            if ($input->getOption('dry-run')) {
                $this->em->rollback();
                $io->table(['Table', 'Lignes lues'], $counts);
                $io->success('Vérification réussie : rien n\'a été enregistré (--dry-run).');

                return Command::SUCCESS;
            }
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            $io->error('Import annulé, rien n\'a été enregistré : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Table', 'Lignes importées'], $counts);
        if ($passwords) {
            $io->warning('Mots de passe temporaires (affichés une seule fois) : à transmettre aux personnes, qui les changeront dans « Compte ».');
            $io->table(['E-mail', 'Mot de passe temporaire'], $passwords);
        }
        $io->success('Import terminé.');

        return Command::SUCCESS;
    }

    /** @return array<int, User> ancien id => compte */
    private function importUsers(array &$passwords): array
    {
        $out = [];
        $repo = $this->em->getRepository(User::class);
        foreach ($this->rows('users') as $r) {
            $email = mb_strtolower(trim($r['email']));
            $user = $repo->findOneBy(['email' => $email]);
            if (!$user) { // compte existant (ex. administrateur créé au démarrage) : on garde son mot de passe
                $user = new User();
                [$user->email, $user->name, $user->isAdmin, $user->isActive] = [$email, (string) $r['name'], (bool) $r['is_admin'], (bool) $r['is_active']];
                $temp = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
                $user->password = $this->hasher->hashPassword($user, $temp);
                $this->em->persist($user);
                if ($user->isActive) {
                    $passwords[] = [$email, $temp];
                }
            }
            $out[$r['id']] = $user;
        }

        return $out;
    }

    /** Crée une entité par ligne de la table ; retourne ancien id => entité. */
    private function map(string $table, callable $build): array
    {
        $out = [];
        foreach ($this->rows($table) as $r) {
            $entity = $build($r);
            $this->em->persist($entity);
            $out[$r['id']] = $entity;
        }

        return $out;
    }

    private function rows(string $table): \Generator
    {
        $order = 'user_company' === $table ? '' : ' ORDER BY id';
        yield from $this->src->query("SELECT * FROM $table$order");
    }

    private static function date(?string $v): ?\DateTimeImmutable
    {
        return $v ? new \DateTimeImmutable(substr($v, 0, 10)) : null;
    }

    private static function dec(mixed $v, int $scale = 2): string
    {
        return number_format((float) $v, $scale, '.', '');
    }
}
