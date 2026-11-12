<?php

namespace App\Service;

use App\Entity\Participation;
use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Droit d'accès et portabilité (RGPD, art. 15 et 20) : toutes les données d'une personne de l'annuaire, dans un fichier
 * JSON lisible (fiche, puis chaque date où elle figure : rôle, jours, hébergement, trajets, dépenses).
 */
class PersonExport
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** $amounts : prix des billets et dépenses inclus (droit de voir les finances de la compagnie). */
    public function export(Person $p, bool $amounts): array
    {
        $parts = $this->em->getRepository(Participation::class)->findBy(['person' => $p]);
        usort($parts, fn ($a, $b) => $a->event->firstDate <=> $b->event->firstDate);
        $date = fn (?\DateTimeInterface $d) => $d?->format('Y-m-d');

        return [
            'export' => [
                'genere_le' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(\DATE_ATOM),
                'responsable_du_traitement' => $p->company->name,
                'montants_inclus' => $amounts,
            ],
            'fiche' => [
                'nom' => $p->lastName, 'prenom' => $p->firstName, 'fonction' => $p->function, 'telephone' => $p->phone,
                'email' => $p->email, 'regime_alimentaire' => $p->diet, 'ville_de_depart' => $p->homeCity,
            ],
            'dates' => array_map(fn (Participation $x) => [
                'spectacle' => $x->event->show->title, 'lieu' => $x->event->venue, 'ville' => $x->event->city,
                'du' => $date($x->event->firstDate), 'au' => $date($x->event->lastDate), 'statut' => $x->event->status,
                'role' => $x->role, 'ville_de_depart' => $x->departureCity, 'chambre' => Participation::ROOM_TYPES[$x->roomType] ?? $x->roomType,
                'chambre_partagee_avec' => null === $x->roomGroup ? [] : array_values(array_map(fn ($o) => $o->person->fullName(),
                    array_filter($x->event->participations->toArray(), fn ($o) => $o !== $x && $o->roomGroup === $x->roomGroup))),
                'hebergement' => $x->lodging ? ['nom' => $x->lodging->name, 'adresse' => $x->lodging->address] : null,
                'note' => $x->note,
                'jours' => $x->days->map(fn ($d) => ['jour' => $date($d->day), 'nuitee' => $d->night, 'repas' => $d->meals, 'petit_dejeuner' => $d->breakfast])->getValues(),
                'trajets' => $x->legs->map(fn ($l) => ['sens' => 'return' === $l->direction ? 'retour' : 'aller', 'jour' => $date($l->day),
                    'de' => $l->origin, 'a' => $l->destination, 'depart' => $l->depTime, 'arrivee' => $l->arrTime, 'mode' => $l->mode,
                    'classe' => $l->travelClass, 'reference' => $l->reference, 'lien_du_billet' => $l->bookingUrl, 'reserve_le' => $date($l->bookedOn),
                    'note' => $l->note] + ($amounts ? ['prix' => (float) $l->price] : []))->getValues(),
                'depenses_et_remboursements' => $amounts ? $x->expenses->map(fn ($e) => ['sens' => 'return' === $e->direction ? 'retour' : 'aller',
                    'jour' => $date($e->day), 'type' => $e->kind, 'montant' => (float) $e->amount, 'commentaire' => $e->comment])->getValues() : null,
            ], $parts),
        ];
    }
}
