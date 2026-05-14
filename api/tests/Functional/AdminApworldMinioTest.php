<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\GameSelection\Domain\GameCatalogSync;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Sessions\Infrastructure\NullRunnerGateway;
use App\Shared\Infrastructure\NullMinioStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminApworldMinioTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;
    private NullMinioStorage $minioStorage;

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

        $minioStorage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $minioStorage);
        $this->minioStorage = $minioStorage;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        NullRunnerGateway::reset();
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testApworldUploadStoresInMinioAndSetsMinioKey(): void
    {
        $sha256 = hash('sha256', 'fake apworld content');

        NullRunnerGateway::$apworldUploadResult = [
            'storageKey' => $sha256.'.apworld',
            'hash' => $sha256,
            'archipelagoGameName' => 'Hollow Knight',
            'defaultYaml' => "name: Hollow Knight\ngame: Hollow Knight\n",
        ];

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGame();

        $tmpFile = $this->createTempApworld('fake apworld content');
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $minioKey = $sha256.'.apworld';
        self::assertTrue($this->minioStorage->exists('apworlds', $minioKey), 'APWorld should be stored in MinIO');

        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);
        self::assertInstanceOf(ArchipelagoGame::class, $game);
        self::assertSame($minioKey, $game->getApworldMinioKey());
    }

    public function testApworldUploadDeduplicatesIfAlreadyInMinio(): void
    {
        $sha256 = hash('sha256', 'same content');
        $minioKey = $sha256.'.apworld';

        NullRunnerGateway::$apworldUploadResult = [
            'storageKey' => $minioKey,
            'hash' => $sha256,
            'archipelagoGameName' => 'Hollow Knight',
            'defaultYaml' => "name: Hollow Knight\ngame: Hollow Knight\n",
        ];

        // Pre-seed MinIO so the second upload is skipped
        $this->minioStorage->upload('apworlds', $minioKey, 'same content');
        $storeBefore = count($this->minioStorage->getStore());

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGame();

        $tmpFile = $this->createTempApworld('same content');
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        // Store count must not have increased (deduplication)
        self::assertCount($storeBefore, $this->minioStorage->getStore());

        $game = $this->entityManager->find(ArchipelagoGame::class, $gameId);
        self::assertInstanceOf(ArchipelagoGame::class, $game);
        self::assertSame($minioKey, $game->getApworldMinioKey());
    }

    public function testDownloadUrlEndpointReturnsPresignedUrl(): void
    {
        $sha256 = hash('sha256', 'apworld bytes');
        $minioKey = $sha256.'.apworld';

        $this->minioStorage->upload('apworlds', $minioKey, 'apworld bytes');

        $game = ArchipelagoGame::create(
            'Hollow Knight',
            'hollow-knight',
            'A Metroidvania.',
            null,
            'Hollow Knight cover',
            '',
            ArchipelagoGame::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable(),
        );
        $game->configureApworld($minioKey, $sha256, 'Hollow Knight', "game: Hollow Knight\n", new \DateTimeImmutable());
        $game->setApworldMinioKey($minioKey);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'GET',
            sprintf('/api/v1/admin/sessions/session-stub/apworlds/%s/download-url', $sha256),
        );

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['url']);
        self::assertStringContainsString($minioKey, $data['url']);
        self::assertIsInt($data['expiresIn']);
        self::assertGreaterThan(0, $data['expiresIn']);
    }

    public function testDownloadUrlEndpointReturns404WhenApworldNotInMinio(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/sessions/session-stub/apworlds/unknownhash/download-url');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadUrlEndpointRequiresAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/sessions/session-stub/apworlds/somehash/download-url');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);
        $this->client->jsonRequest('GET', '/api/v1/admin/sessions/session-stub/apworlds/somehash/download-url');
        self::assertResponseStatusCodeSame(403);
    }

    private function createTempApworld(string $contents = 'fake apworld content'): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_apworld_');
        rename($tmpFile, $tmpFile.'.apworld');
        $tmpFile = $tmpFile.'.apworld';
        file_put_contents($tmpFile, $contents);

        return $tmpFile;
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

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
