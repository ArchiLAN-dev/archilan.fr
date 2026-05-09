<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminGameApworldTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousCannotUploadApworld(): void
    {
        $this->client->request('PATCH', '/api/v1/admin/games/nonexistent/apworld');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaCannotUploadApworld(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->request('PATCH', '/api/v1/admin/games/nonexistent/apworld');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminOnNonExistentGameReturnsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $tmpFile = $this->createTempApworld();
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', '/api/v1/admin/games/nonexistent/apworld', [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(404);

        unlink($tmpFile);
    }

    public function testAdminWithoutFileReceivesValidationError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGame();

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminWithInvalidExtensionReceivesValidationError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGame();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'not an apworld');
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.zip', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertArrayHasKey('file', $details);

        unlink($tmpFile);
    }

    public function testAdminWithValidApworldButUnavailableRunnerReceivesError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGame();

        $tmpFile = $this->createTempApworld();
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        // NullRunnerGateway returns runner_unavailable - expect 422
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertArrayHasKey('file', $details);

        unlink($tmpFile);
    }

    private function createTempApworld(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_apworld_');
        rename($tmpFile, $tmpFile.'.apworld');
        $tmpFile = $tmpFile.'.apworld';
        file_put_contents($tmpFile, 'fake apworld content');

        return $tmpFile;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    private function createGame(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => 'Hollow Knight',
            'slug' => 'hollow-knight',
            'description' => 'Un metroidvania compatible Archipelago.',
            'coverImageAlt' => 'Logo Hollow Knight',
            'coverImageCredit' => 'Team Cherry',
            'availability' => ArchipelagoGame::AVAILABILITY_AVAILABLE,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $id = $data['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
