<?php

namespace App\Security;

/**
 * Catalogue des droits. Un groupe en accorde une sélection ; les droits d'un utilisateur sont la somme de ceux de ses groupes.
 *
 * - Droits « compagnie » : valent pour les compagnies du périmètre du groupe (toutes, ou une liste).
 * - Droits « administration » : globaux ; seul un super-administrateur peut les attribuer.
 */
final class Permissions
{
    public const COMPANY = [
        'company.view' => ['Consulter', 'Voir la compagnie, ses spectacles, dates, équipes, hébergements et voyages (sans les montants ni les coordonnées)'],
        'events.edit' => ['Organiser les dates', 'Créer et modifier les dates : infos, équipe, séjour, planning, infos pratiques'],
        'travel.edit' => ['Gérer les voyages', 'Saisir les trajets, billets et liens de réservation'],
        'people.view' => ['Voir les coordonnées', 'Téléphones, e-mails et régimes alimentaires des personnes'],
        'people.edit' => ['Gérer l\'annuaire', 'Ajouter, modifier et supprimer des personnes'],
        'finance.view' => ['Voir les montants', 'Devis, cachets, prix des billets, défraiements, export Excel'],
        'finance.edit' => ['Modifier le devis', 'Cachet, tarifs, transport du décor, frais supplémentaires'],
        'shows.edit' => ['Gérer les spectacles', 'Créer, renommer et supprimer des spectacles'],
        'share.manage' => ['Partager la feuille de route', 'Créer, envoyer et révoquer le lien public de la feuille de route'],
        'company.manage' => ['Paramètres de la compagnie', 'Renommer la compagnie, modifier ses notes'],
    ];

    public const GLOBAL = [
        'users.manage' => ['Gérer les utilisateurs', 'Inviter, désactiver, supprimer des comptes ; liens de réinitialisation ; journal'],
        'groups.manage' => ['Gérer les groupes', 'Créer des groupes, choisir leurs droits, leurs compagnies et leurs membres'],
        'companies.create' => ['Créer des compagnies', 'Le créateur reçoit un groupe « Équipe » avec tous les droits sur la nouvelle compagnie'],
        'companies.delete' => ['Supprimer des compagnies', 'Suppression définitive avec tout leur contenu'],
        'lists.manage' => ['Gérer les listes', 'Fonctions des personnes et intitulés de planning proposés dans les listes déroulantes'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...array_keys(self::COMPANY), ...array_keys(self::GLOBAL)];
    }

    /** Ne garde que des droits connus (une liste reçue du navigateur n'est jamais prise telle quelle). */
    public static function clean(array $perms): array
    {
        return array_values(array_intersect(self::all(), array_map('strval', $perms)));
    }
}
