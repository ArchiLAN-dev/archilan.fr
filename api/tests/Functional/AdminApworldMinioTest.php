<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Sessions\Infrastructure\NullRunnerGateway;
use App\Shared\Infrastructure\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminApworldMinioTest extends FunctionalTestCase
{
    private NullMinioStorage $minioStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $minioStorage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $minioStorage);
        $this->minioStorage = $minioStorage;

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
        $gameId = $this->createGameViaApi();

        $tmpFile = $this->createTempApworld('fake apworld content');
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $minioKey = $sha256.'.apworld';
        self::assertTrue($this->minioStorage->exists('apworlds', $minioKey), 'APWorld should be stored in MinIO');

        $game = $this->entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $game);
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
        $gameId = $this->createGameViaApi();

        $tmpFile = $this->createTempApworld('same content');
        $uploadedFile = new UploadedFile($tmpFile, 'hollow_knight.apworld', 'application/octet-stream', null, true);

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        // Store count must not have increased (deduplication)
        self::assertCount($storeBefore, $this->minioStorage->getStore());

        $game = $this->entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $game);
        self::assertSame($minioKey, $game->getApworldMinioKey());
    }

    public function testDownloadUrlEndpointReturnsPresignedUrl(): void
    {
        $sha256 = hash('sha256', 'apworld bytes');
        $minioKey = $sha256.'.apworld';

        $this->minioStorage->upload('apworlds', $minioKey, 'apworld bytes');

        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->configureApworld($minioKey, $sha256, 'Hollow Knight', "game: Hollow Knight\n", new \DateTimeImmutable());
        $game->setApworldMinioKey($minioKey);
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

        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);
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

    private function createGameViaApi(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => 'Hollow Knight',
            'slug' => 'hollow-knight',
            'description' => 'Un metroidvania compatible Archipelago.',
            'coverImageAlt' => 'Logo Hollow Knight',
            'coverImageCredit' => 'Team Cherry',
            'availability' => Game::AVAILABILITY_AVAILABLE,
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $id = $data['id'];
        self::assertIsString($id);

        return $id;
    }
}
