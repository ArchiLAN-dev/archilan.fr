<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminGameApworldTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Game::class),
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

    public function testStandardCannotUploadApworld(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

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
        $gameId = $this->createGameViaApi();

        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $gameId));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminWithInvalidExtensionReceivesValidationError(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $gameId = $this->createGameViaApi();

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
        $gameId = $this->createGameViaApi();

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
