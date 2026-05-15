<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminGameLibraryTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousAndLambdaCannotManageGameLibrary(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesEmptyGameLibrary(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertSame([], $response['data']);
    }

    public function testAdminCreatesUpdatesListsAndDeletesGame(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('The Legend of Zelda: Ocarina of Time', $response['data']['name']);
        self::assertSame('ocarina-of-time', $response['data']['slug']);
        self::assertSame(0, $response['data']['usageCount']);

        $gameId = $response['data']['id'];
        self::assertIsString($gameId);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s', $gameId), [
            ...$this->validPayload(),
            'name' => 'Ocarina of Time AP',
            'slug' => 'oot-ap',
            'availability' => ArchipelagoGame::AVAILABILITY_EXPERIMENTAL,
        ]);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Ocarina of Time AP', $response['data']['name']);
        self::assertSame('oot-ap', $response['data']['slug']);
        self::assertSame(ArchipelagoGame::AVAILABILITY_EXPERIMENTAL, $response['data']['availability']);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/games/%s', $gameId));
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertSame([], $list['data']);
    }

    public function testValidationErrorsAndDuplicateSlugAreReturned(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/games', [
            'name' => '',
            'slug' => 'Invalid Slug!',
            'description' => '',
            'coverImageAlt' => '',
            'coverImageCredit' => '',
            'availability' => 'unknown',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        foreach (['name', 'slug', 'description', 'availability'] as $field) {
            self::assertArrayHasKey($field, $response['error']['details']);
        }

        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/games', $this->validPayload());
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('slug', $response['error']['details']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'The Legend of Zelda: Ocarina of Time',
            'slug' => 'ocarina-of-time',
            'description' => 'Un classique compatible Archipelago avec progression multiworld.',
            'coverImageAlt' => 'Logo Ocarina of Time',
            'coverImageCredit' => 'Nintendo',
            'availability' => ArchipelagoGame::AVAILABILITY_AVAILABLE,
        ];
    }
}
