<?php

namespace App\Tests;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Logo d'une compagnie : envoi contrôlé et réencodé, affichage, PDF, droits. */
class CompanyLogoTest extends ApiTestCase
{
    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 91, 46, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function send(int $cid, UploadedFile $file): ?array
    {
        $this->client->request('POST', "/api/companies/$cid/logo", files: ['logo' => [$file]], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testCompanyLogo(): void
    {
        $this->login();
        [$cid, [$show]] = $this->buildCompany();
        self::assertNull($this->call('GET', "/api/companies/$cid")['logo']);

        $this->send($cid, $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
        self::assertSame(400, $this->code(), 'SVG refusé');
        $this->send($cid, $this->file('logo.png', 'pas une image'));
        self::assertSame(400, $this->code());

        $company = $this->send($cid, $this->file('logo.png', $this->png(1600, 400)));
        self::assertSame(200, $this->code());
        self::assertMatchesRegularExpression("~^/api/companies/$cid/logo\\?v=\\d+$~", $company['logo']);
        self::assertSame($company['logo'], array_column($this->call('GET', '/api/companies'), null, 'id')[$cid]['logo']);

        $this->client->request('GET', $company['logo']);
        self::assertSame('image/png', $this->client->getResponse()->headers->get('content-type'));
        $size = getimagesizefromstring($this->client->getResponse()->getContent());
        self::assertSame([800, 200], [$size[0], $size[1]], 'réduit à 800 px de large au plus');

        $ev = $this->call('POST', "/api/shows/$show/events", ['venue' => 'Salle', 'firstDate' => '2027-01-19']);
        self::assertSame($company['logo'], $ev['companyLogo']);
        $this->client->request('GET', "/api/events/{$ev['id']}/pdf/roadmap");
        self::assertSame(200, $this->code(), 'PDF avec logo');

        // Droits : voir avec « Consulter », modifier avec « Paramètres de la compagnie »
        $this->createUser('ed@test.io', 'ed-password-12345');
        $this->grant(['ed@test.io'], ['company.view'], [$cid]);
        $this->call('POST', '/api/logout');
        $this->login('ed@test.io', 'ed-password-12345');
        $this->client->request('GET', "/api/companies/$cid/logo");
        self::assertSame(200, $this->code());
        $this->send($cid, $this->file('logo.png', $this->png(10, 10)));
        self::assertSame(403, $this->code());
        $this->call('DELETE', "/api/companies/$cid/logo");
        self::assertSame(403, $this->code());
        $this->call('POST', '/api/logout');
        $this->client->request('GET', "/api/companies/$cid/logo");
        self::assertSame(401, $this->code(), 'pas sans compte');

        $this->login();
        self::assertNull($this->call('DELETE', "/api/companies/$cid/logo")['logo']);
        $this->client->request('GET', "/api/companies/$cid/logo");
        self::assertSame(404, $this->code());
    }
}
