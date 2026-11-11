<?php

namespace App\Tests;

/** Équipe habituelle d'un spectacle : choisie avec le spectacle, cochée d'office à la création d'une date. */
class ShowTeamTest extends ApiTestCase
{
    public function testDefaultTeamPrefillsNewDates(): void
    {
        $this->login();
        [$cid] = $this->buildCompany();
        $people = array_column($this->call('GET', "/api/companies/$cid")['people'], 'id', 'firstName');
        $other = $this->call('POST', '/api/companies', ['name' => 'Autre']);
        $this->call('POST', "/api/companies/{$other['id']}/people", ['lastName' => 'EXTERNE']);
        $outsider = $this->call('GET', "/api/companies/{$other['id']}")['people'][0]['id'];

        // Création du spectacle avec son équipe habituelle (une personne d'une autre compagnie est ignorée)
        $c = $this->call('POST', "/api/companies/$cid/shows", ['title' => 'Le Grand Saut', 'defaultPersonIds' => [$people['Anna'], $people['Luc'], $outsider]]);
        $show = array_column($c['shows'], null, 'title')['Le Grand Saut'];
        self::assertEqualsCanonicalizing([$people['Anna'], $people['Luc']], $show['defaultPersonIds']);

        // Date sans précision : toute l'équipe habituelle ; avec « personIds » : seulement les personnes cochées
        $ev = $this->call('POST', "/api/shows/{$show['id']}/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19', 'lastDate' => '2027-01-20']);
        self::assertCount(2, $ev['participations']);
        self::assertNotEmpty($ev['participations'][0]['days'], 'jours de présence préremplis');
        $ev = $this->call('POST', "/api/shows/{$show['id']}/events", ['venue' => 'Autre salle', 'firstDate' => '2027-02-19', 'personIds' => [$people['Luc']]]);
        self::assertSame(['Luc'], array_map(fn ($p) => $p['person']['firstName'], $ev['participations']));

        // Modification : l'équipe habituelle change ; sans « defaultPersonIds », elle reste
        $c = $this->call('PUT', "/api/shows/{$show['id']}", ['title' => 'Le Grand Saut', 'defaultPersonIds' => [$people['Anna']]]);
        self::assertSame([$people['Anna']], array_column($c['shows'], null, 'id')[$show['id']]['defaultPersonIds']);
        $c = $this->call('PUT', "/api/shows/{$show['id']}", ['title' => 'Le Grand Saut (reprise)']);
        self::assertSame([$people['Anna']], array_column($c['shows'], null, 'id')[$show['id']]['defaultPersonIds']);

        // Personne supprimée de l'annuaire : retirée de l'équipe habituelle
        $this->call('DELETE', "/api/people/{$people['Anna']}");
        self::assertSame([], array_column($this->call('GET', "/api/companies/$cid")['shows'], null, 'id')[$show['id']]['defaultPersonIds']);
    }
}
