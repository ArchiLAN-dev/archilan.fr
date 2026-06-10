<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;

final class AdminGameLibraryTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAnonymousAndUserCannotManageGameLibrary(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

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
            'availability' => Game::AVAILABILITY_EXPERIMENTAL,
        ]);
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Ocarina of Time AP', $response['data']['name']);
        self::assertSame('oot-ap', $response['data']['slug']);
        self::assertSame(Game::AVAILABILITY_EXPERIMENTAL, $response['data']['availability']);

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

    public function testApworldReadyFilterPartitionsGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $now = new \DateTimeImmutable();

        $ready = Game::create('Ocarina of Time', 'ocarina-of-time', 'Un classique.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, $now);
        $ready->configureApworld('apworlds/oot.apworld', 'hash123', 'Ocarina of Time', "name: ArchiLAN\n", $now);

        $notReady = Game::create('Super Metroid', 'super-metroid', 'Pas encore prêt.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, $now);

        $this->entityManager->persist($ready);
        $this->entityManager->persist($notReady);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // apworld_ready=1 → only the APWorld-ready game
        $this->client->jsonRequest('GET', '/api/v1/admin/games?apworld_ready=1');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);
        $readyRow = $list['data'][0];
        self::assertIsArray($readyRow);
        self::assertSame('Ocarina of Time', $readyRow['name']);
        self::assertTrue($readyRow['isApworldReady']);
        self::assertIsArray($list['meta']);
        self::assertSame(1, $list['meta']['total']);

        // apworld_ready=0 → only the not-ready game
        $this->client->jsonRequest('GET', '/api/v1/admin/games?apworld_ready=0');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);
        $notReadyRow = $list['data'][0];
        self::assertIsArray($notReadyRow);
        self::assertSame('Super Metroid', $notReadyRow['name']);
        self::assertFalse($notReadyRow['isApworldReady']);

        // apworld_ready=1 + matching search (ILIKE) → the ready game
        $this->client->jsonRequest('GET', '/api/v1/admin/games?apworld_ready=1&search=ocarina');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(1, $list['data']);

        // apworld_ready=1 + search matching only a not-ready game → empty; meta.total reflects the filtered set
        $this->client->jsonRequest('GET', '/api/v1/admin/games?apworld_ready=1&search=metroid');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertSame([], $list['data']);
        self::assertIsArray($list['meta']);
        self::assertSame(0, $list['meta']['total']);

        // no filter → both games (unchanged behaviour)
        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        self::assertCount(2, $list['data']);
    }

    public function testUsageCountAndSorting(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $now = new \DateTimeImmutable();
        $alpha = Game::create('Alpha', 'alpha', 'A.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, $now);
        $beta = Game::create('Beta', 'beta', 'B.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, $now);
        $this->entityManager->persist($alpha);
        $this->entityManager->persist($beta);
        $this->entityManager->flush();

        // Alpha is used by two weekly templates; Beta by none.
        $conn = $this->entityManager->getConnection();
        foreach (['wt-usage-1', 'wt-usage-2'] as $id) {
            $conn->insert('weekly_templates', [
                'id' => $id,
                'game_id' => $alpha->getId(),
                'yaml_config' => "name: x\n",
                'is_active' => true,
                'created_at' => $now->format('Y-m-d H:i:sP'),
                'updated_at' => $now->format('Y-m-d H:i:sP'),
            ], ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        }
        $this->entityManager->clear();

        // sort=usage desc → Alpha (2) before Beta (0)
        $this->client->jsonRequest('GET', '/api/v1/admin/games?sort=usage&dir=desc');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        $first = $list['data'][0];
        $second = $list['data'][1];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('Alpha', $first['name']);
        self::assertSame(2, $first['usageCount']);
        self::assertSame('Beta', $second['name']);
        self::assertSame(0, $second['usageCount']);

        // sort=name desc → Beta before Alpha
        $this->client->jsonRequest('GET', '/api/v1/admin/games?sort=name&dir=desc');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse();
        self::assertIsArray($list['data']);
        $first = $list['data'][0];
        $second = $list['data'][1];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('Beta', $first['name']);
        self::assertSame('Alpha', $second['name']);
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
            'availability' => Game::AVAILABILITY_AVAILABLE,
        ];
    }
}
