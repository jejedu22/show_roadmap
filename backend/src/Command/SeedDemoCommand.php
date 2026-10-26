<?php

namespace App\Command;

use App\Entity\Company;
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
use App\Service\Planning;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Données fictives : deux spectacles de la même compagnie sur des dates qui se chevauchent,
 * avec une personne commune, pour illustrer la détection de conflit.
 */
#[AsCommand('app:seed-demo', 'Ajoute des données de démonstration fictives')]
class SeedDemoCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private Planning $planning)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->em->getRepository(Company::class)->findOneBy(['name' => 'Compagnie Démo'])) {
            $output->writeln('Données de démo déjà présentes.');

            return Command::SUCCESS;
        }
        $d = fn (string $s) => new \DateTimeImmutable($s);
        $c = new Company('Compagnie Démo');
        foreach ($this->em->getRepository(User::class)->findAll() as $u) {
            $u->companies->add($c);
        }
        $murmure = new Show($c, 'Le Murmure des songes');
        $prelude = new Show($c, 'Prélude');
        $people = [];
        foreach ([['MARTIN', 'Alice', 'Admin tournée', 'Lyon', ''], ['DURAND', 'Bruno', 'Régisseur lumière', 'Marseille', ''],
            ['LEROY', 'Chloé', 'Danseuse', 'Paris', 'Végétarienne'], ['PETIT', 'David', 'Danseur', 'Lille', ''],
            ['MOREAU', 'Emma', 'Danseuse', 'Paris', '']] as $i => [$ln, $fn, $fc, $city, $diet]) {
            $p = new Person($c);
            [$p->lastName, $p->firstName, $p->function, $p->homeCity, $p->diet] = [$ln, $fn, $fc, $city, $diet];
            $p->phone = sprintf('06 00 00 00 %02d', $i + 1);
            $p->email = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $fn)).'@example.org';
            $people[] = $p;
            $this->em->persist($p);
        }

        $ev1 = new Event($murmure, 'Théâtre du Beauvaisis — Scène nationale', $d('2026-05-19'), $d('2026-05-23'));
        [$ev1->city, $ev1->address, $ev1->techArrival, $ev1->artisticArrival, $ev1->departure, $ev1->performances] =
            ['Beauvais', '3 place Georges Brassens, 60000 Beauvais', $d('2026-05-16'), $d('2026-05-18'), $d('2026-05-23'), 3];
        [$ev1->fee, $ev1->venueContact] = ['9000.00', 'Accueil : Jeanne Exemple — 03 00 00 00 00'];
        $ev2 = new Event($prelude, 'Festival Exemple', $d('2026-05-21'), $d('2026-05-22'));
        [$ev2->city, $ev2->artisticArrival, $ev2->departure, $ev2->performances, $ev2->fee] = ['Strasbourg', $d('2026-05-20'), $d('2026-05-23'), 2, '5000.00'];

        $hotel = new Lodging($ev1);
        [$hotel->name, $hotel->address, $hotel->details] = ['Hôtel Cathédrale', '11 rue Chambiges, 60000 Beauvais',
            'Check-in 15h / check-out 10h30. Petit-déjeuner 7h–9h.'];
        $ev1->lodgings->add($hotel);
        foreach (array_slice($people, 0, 4) as $p) {
            $part = new Participation($ev1, $p);
            $part->lodging = $hotel;
            $ev1->participations->add($part);
            $this->planning->fillDefaultDays($part);
        }
        foreach ([$people[3], $people[4]] as $p) { // David est aussi sur Prélude → conflit signalé
            $part = new Participation($ev2, $p);
            $ev2->participations->add($part);
            $this->planning->fillDefaultDays($part);
        }
        $first = $ev1->participations->first();
        foreach ([['outbound', '2026-05-16', 'Lyon', 'Paris', '9h00', '11h00', 'TGV', 'ABC123', '79.00'],
            ['return', '2026-05-23', 'Beauvais', 'Paris', '19h39', '20h57', 'TER', 'XYZ789', '12.40']] as $l) {
            $leg = new Leg($first, $d($l[1]));
            [$leg->direction, , $leg->origin, $leg->destination, $leg->depTime, $leg->arrTime, $leg->mode, $leg->reference, $leg->price] = $l;
            $first->legs->add($leg);
        }
        $truck = new TruckLeg($ev1);
        [$truck->label, $truck->km] = ['Paris → Beauvais', '90.0'];
        $ev1->trucks->add($truck);
        $extra = new ExtraCost($ev1);
        [$extra->label, $extra->amount, $extra->kind] = ['Métro Paris (2 × 4)', '8.00', 'team_transport'];
        $ev1->extras->add($extra);
        $section = new RoadmapSection($ev1);
        [$section->title, $section->body, $section->position] = ['ACCÈS AU THÉÂTRE', "Entrée par l'arrière du bâtiment, quai de déchargement.", 10];
        $ev1->sections->add($section);
        $slot = new ScheduleItem($ev1);
        [$slot->day, $slot->time, $slot->label] = [$d('2026-05-18'), '10h–18h', 'Montage et réglages'];
        $ev1->schedule->add($slot);

        array_map($this->em->persist(...), [$c, $murmure, $prelude, $ev1, $ev2]);
        $this->em->flush();
        $output->writeln('Données de démo créées.');

        return Command::SUCCESS;
    }
}
