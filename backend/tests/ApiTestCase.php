<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Compteurs de tentatives de connexion (cache disque) remis à zéro à chaque test
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.share_dir').'/pools');
        $em = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->createUser('admin@test.io', 'admin-password-123', true);
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, string $password, bool $admin = false): User
    {
        $u = new User();
        [$u->email, $u->isAdmin] = [$email, $admin];
        $u->password = static::getContainer()->get('security.user_password_hasher')->hashPassword($u, $password);
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    /** Appel JSON avec l'en-tête anti-CSRF ; retourne le corps décodé. */
    protected function call(string $method, string $uri, ?array $body = null, bool $csrf = true): ?array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf) {
            $headers['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $this->client->request($method, $uri, server: $headers, content: null === $body ? '' : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    protected function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function login(string $email = 'admin@test.io', string $password = 'admin-password-123'): void
    {
        $this->call('POST', '/api/login', ['email' => $email, 'password' => $password]);
        self::assertSame(200, $this->code());
    }

    /** Compagnie avec 2 personnes et 2 spectacles ; retourne [companyId, showIds]. */
    protected function buildCompany(string $name = 'Cie Test'): array
    {
        $c = $this->call('POST', '/api/companies', ['name' => $name]);
        foreach ([['DURAND', 'Anna', 'Danseuse'], ['MARTIN', 'Luc', 'Régisseur lumière']] as [$ln, $fn, $fc]) {
            $this->call('POST', "/api/companies/{$c['id']}/people", ['lastName' => $ln, 'firstName' => $fn, 'function' => $fc, 'homeCity' => 'Paris']);
        }
        $this->call('POST', "/api/companies/{$c['id']}/shows", ['title' => 'Spectacle A']);
        $c = $this->call('POST', "/api/companies/{$c['id']}/shows", ['title' => 'Spectacle B']);

        return [$c['id'], array_column($c['shows'], 'id')];
    }
}
