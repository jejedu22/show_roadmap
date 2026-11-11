<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\CompanyLogo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Logo des compagnies : PNG, JPEG ou WebP envoyé dans les Paramètres, vérifié puis réencodé en PNG (métadonnées retirées,
 * transparence gardée), réduit à 800 × 400 au plus. Le SVG est refusé : il peut contenir du code.
 */
class CompanyLogos
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_W = 800;
    private const MAX_H = 400;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function store(Company $company, ?UploadedFile $file): void
    {
        if (!$file || !$file->isValid()) {
            throw new BadRequestHttpException('Aucune image reçue.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException('Image trop lourde : 2 Mo au plus.');
        }
        $bytes = (string) file_get_contents($file->getPathname());
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new BadRequestHttpException('Format non pris en charge : envoyez une image PNG, JPEG ou WebP.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new BadRequestHttpException('Image trop grande.');
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new BadRequestHttpException('Image illisible.');
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $ratio = min(1, self::MAX_W / $w, self::MAX_H / $h);
        [$nw, $nh] = [max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio))];
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst, null, 9);
        $png = (string) ob_get_clean();

        $logo = $this->em->find(CompanyLogo::class, $company->id);
        if ($logo) {
            $logo->data = $png;
        } else {
            $this->em->persist(new CompanyLogo($company, $png));
        }
        $company->logoAt = new \DateTimeImmutable();
    }

    public function remove(Company $company): void
    {
        if ($logo = $this->em->find(CompanyLogo::class, $company->id)) {
            $this->em->remove($logo);
        }
        $company->logoAt = null;
    }

    public function png(Company $company): ?string
    {
        return $company->logoAt ? $this->em->find(CompanyLogo::class, $company->id)?->png() : null;
    }

    /**
     * Pour les PDF (aucune ressource externe n'y est chargée) : image en ligne et taille en points, ajustée à $maxW × $maxH.
     *
     * @return array{src: string, w: float, h: float}|null
     */
    public function forPdf(Company $company, float $maxW = 130, float $maxH = 52): ?array
    {
        $png = $this->png($company);
        $info = $png ? @getimagesizefromstring($png) : false;
        if (!$info) {
            return null;
        }
        $ratio = min($maxW / $info[0], $maxH / $info[1]);

        return ['src' => 'data:image/png;base64,'.base64_encode($png), 'w' => round($info[0] * $ratio, 1), 'h' => round($info[1] * $ratio, 1)];
    }

    /** Adresse du logo pour l'interface (version dans l'adresse : le navigateur peut le garder en cache). */
    public static function url(Company $company): ?string
    {
        return $company->logoAt ? sprintf('/api/companies/%d/logo?v=%d', $company->id, $company->logoAt->getTimestamp()) : null;
    }
}
