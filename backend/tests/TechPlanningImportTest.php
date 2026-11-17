<?php

namespace App\Tests;

use App\Entity\Event;
use App\Service\Import\TechPlanningParser;
use App\Service\Lists;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import du planning d'une fiche technique PDF (texte de « pdftotext -layout », exemples anonymisés) : jours, créneaux,
 * intitulé rapproché de la liste, texte de la fiche en précision ; effectifs et remarques non repris.
 */
class TechPlanningImportTest extends ApiTestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__."/fixtures/planning/$name.txt");
    }

    private function parser(): TechPlanningParser
    {
        return new TechPlanningParser(static::getContainer()->get(Lists::class));
    }

    private static function event(string $first, string $last): Event
    {
        $ev = (new \ReflectionClass(Event::class))->newInstanceWithoutConstructor();
        [$ev->firstDate, $ev->lastDate] = [new \DateTimeImmutable($first), new \DateTimeImmutable($last)];
        [$ev->techArrival, $ev->artisticArrival, $ev->departure] = [null, null, null];

        return $ev;
    }

    public function testParseFiches(): void
    {
        $a = $this->parser()->parse(self::fixture('fiche-a'), self::event('2026-11-21', '2026-11-21'));
        self::assertSame([2, 2], [$a['page'], $a['pages']], 'dernière page');
        self::assertSame([], $a['warnings']);
        $rows = array_map(fn ($r) => [$r['day'], $r['time'], $r['label'], $r['checked']], $a['rows']);
        self::assertSame(['2026-11-19', '', 'Montage', false], $rows[0], 'pré-montage « en amont », sans horaire : décoché');
        self::assertSame(['2026-11-19', '14h–18h', 'Réglage lumière', true], $rows[1]);
        self::assertSame(['2026-11-21', '20h', 'Représentation', true], $rows[9]);
        self::assertSame(['2026-11-21', '21h45–22h45', 'Démontage', true], $rows[10]);
        self::assertCount(11, $rows);
        self::assertSame('Arrivée du régisseur lumière. Marquage + début réglage', $a['rows'][1]['detail'], 'activité sur deux lignes, sans remarques');
        self::assertSame('', $a['rows'][9]['detail'], 'texte identique à l\'intitulé');
        self::assertSame('P 1 · L 2 · S 1', $a['rows'][9]['staff']);

        // Coquille de date (« Vendredi 29 nov 2026 » est un dimanche) corrigée et signalée ; jour sans mois (« Mardi 17 »)
        $b = $this->parser()->parse(self::fixture('fiche-b'), self::event('2026-11-20', '2026-11-20'));
        self::assertCount(1, $b['warnings']);
        self::assertStringContainsString('dimanche', $b['warnings'][0]);
        $rows = array_map(fn ($r) => [$r['day'], $r['time'], $r['label']], $b['rows']);
        self::assertSame(['2026-11-17', '', 'Chargement'], $rows[0]);
        self::assertSame(['2026-11-20', '20h30', 'Représentation'], $rows[9]);
        self::assertSame(['2026-11-20', '21h45–minuit', 'Démontage'], $rows[10], 'démontage, même si la ligne parle de la représentation');
        self::assertSame('Plateau danseurs / répétitions', $b['rows'][7]['detail']);

        // Intitulés absents de la liste : celui du mot-clé est proposé quand même (nouveau), sinon le début de l'activité
        $lists = static::getContainer()->get(Lists::class);
        $lists->replace('schedule', array_values(array_diff($lists->entries('schedule'), ['Réglage lumière', 'Conduite lumière', 'Conduite'])));
        $c = $this->parser()->parse(self::fixture('fiche-a'), self::event('2026-11-21', '2026-11-21'));
        self::assertSame(['Réglage lumière', true], [$c['rows'][1]['label'], $c['rows'][1]['newLabel']]);
        self::assertSame(['Conduite lumière', true], [$c['rows'][4]['label'], $c['rows'][4]['newLabel']]);
        self::assertSame(['Représentation', false], [$c['rows'][9]['label'], $c['rows'][9]['newLabel']]);
        self::assertSame('Accueil de l\'équipe', TechPlanningParser::newLabel('accueil de l\'équipe. Café en loge'));
        self::assertSame('Marquage au sol', TechPlanningParser::newLabel('Marquage au sol / tapis'));

        $this->expectException(\InvalidArgumentException::class);
        $this->parser()->parse("Fiche technique\nPuissance électrique 63 A", self::event('2026-11-20', '2026-11-20'));
    }

    public function testImportPlanning(): void
    {
        $this->login();
        [, [$show]] = $this->buildCompany();
        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2026-11-21', 'lastDate' => '2026-11-21']);
        $id = $ev['id'];
        $this->call('POST', "/api/events/$id/items/schedule", ['day' => '2026-11-21', 'time' => '20h', 'label' => 'Représentation']);
        $ev = $this->call('POST', "/api/events/$id/items/invitation", ['lastName' => 'Durand', 'performanceId' => $this->call('GET', "/api/events/$id")['invitationPerformances'][0]['id']]);

        // Lecture d'un vrai PDF (texte de la fiche en police à chasse fixe) : rien n'est enregistré
        if (null === shell_exec('command -v pdftotext')) {
            self::markTestIncomplete('pdftotext absent');
        }
        $dompdf = new Dompdf((new Options())->setDefaultFont('DejaVu Sans Mono'));
        $pages = array_map(fn ($p) => '<pre style="font-family:\'DejaVu Sans Mono\';font-size:6pt">'.htmlspecialchars($p).'</pre>', explode("\f", self::fixture('fiche-a')));
        $dompdf->loadHtml(implode('<div style="page-break-after:always"></div>', $pages), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $pdf = tempnam(sys_get_temp_dir(), 'p');
        file_put_contents($pdf, $dompdf->output());
        $this->client->request('POST', "/api/events/$id/schedule/pdf", [], ['file' => new UploadedFile($pdf, 'FT.pdf', 'application/pdf', null, true)], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $read = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Représentation', $read['rows'][9]['label']);
        self::assertCount(1, $this->call('GET', "/api/events/$id")['schedule']);

        // Enregistrement : intitulé hors liste refusé ; ajout ; remplacement (invitation de la représentation à répartir)
        $rows = array_values(array_filter($read['rows'], fn ($r) => $r['checked']));
        $this->call('POST', "/api/events/$id/schedule/import", ['rows' => [['day' => '2026-11-21', 'time' => '9h', 'label' => 'Marquage']]]);
        self::assertSame(400, $this->code());
        $ev = $this->call('POST', "/api/events/$id/schedule/import", ['rows' => $rows]);
        self::assertCount(11, $ev['schedule']);

        // Intitulés nouveaux ajoutés à la liste à l'import (« addLabels ») ; un intitulé existant n'est pas ajouté en double
        $before = $this->call('GET', '/api/lists')['schedule'];
        $ev = $this->call('POST', "/api/events/$id/schedule/import", ['addLabels' => true, 'rows' => [
            ['day' => '2026-11-21', 'time' => '9h', 'label' => 'Marquage', 'detail' => 'tapis'], ['day' => '2026-11-21', 'time' => '9h30', 'label' => 'marquage'],
            ['day' => '2026-11-21', 'time' => '10h', 'label' => 'représentation']]]);
        self::assertSame(200, $this->code());
        self::assertSame([...$before, 'Marquage'], $this->call('GET', '/api/lists')['schedule']);
        self::assertSame(['Marquage', 'Marquage', 'Représentation'], array_values(array_map(fn ($s) => $s['label'], array_filter($ev['schedule'], fn ($s) => in_array($s['time'], ['9h', '9h30', '10h'], true)))));
        $this->call('POST', "/api/events/$id/schedule/import", ['addLabels' => true, 'rows' => [['day' => '2026-11-21', 'label' => 'Autre'], ['label' => 'Encore']]]);
        self::assertSame(400, $this->code(), 'ligne sans jour');
        self::assertNotContains('Autre', $this->call('GET', '/api/lists')['schedule'], 'rien ajouté si l\'import est refusé');
        self::assertCount(14, $this->call('GET', "/api/events/$id")['schedule']);
        $ev = $this->call('POST', "/api/events/$id/schedule/import", ['rows' => $rows, 'replace' => true]);
        self::assertCount(10, $ev['schedule']);
        self::assertSame(['Représentation'], array_column(array_filter($ev['schedule'], fn ($s) => $s['isPerformance']), 'label'));
        self::assertNull($ev['invitations'][0]['performanceId'], 'invitation à répartir sur la nouvelle représentation');

        $this->client->request('POST', "/api/events/$id/schedule/pdf", [], ['file' => new UploadedFile($pdf, 'FT.pdf', 'application/pdf', null, true)], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code());
    }
}
