<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Application\RegisterUser;
use App\PersonalRuns\Domain\YamlTemplate;

final class YamlTemplateTest extends FunctionalTestCase
{
    public function testCreateThenListReturnsOwnerTemplate(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', [
            'gameId' => $game->getId(),
            'name' => 'Mon preset',
            'yaml' => "name: Hero\n",
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/yaml-templates?gameId='.$game->getId());
        self::assertResponseIsSuccessful();
        $data = $this->dataList();
        self::assertCount(1, $data);
        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('Mon preset', $first['name']);
        self::assertSame("name: Hero\n", $first['yaml']);
    }

    public function testDuplicateNameIsRejected(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');
        $this->loginAs($user);

        $payload = ['gameId' => $game->getId(), 'name' => 'Preset', 'yaml' => "name: A\n"];
        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('template_name_taken', $this->errorCode());
    }

    public function testInvalidYamlIsRejected(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', [
            'gameId' => $game->getId(),
            'name' => 'Broken',
            'yaml' => 'name: "unterminated',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_yaml', $this->errorCode());
    }

    public function testListIsScopedToOwner(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', [
            'gameId' => $game->getId(), 'name' => 'Alice preset', 'yaml' => "name: A\n",
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/yaml-templates?gameId='.$game->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->dataList(), "bob never sees alice's templates");
    }

    public function testUpdateRenamesAndUpdatesYaml(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');
        $this->loginAs($user);

        $id = $this->createTemplate($game->getId(), 'Original', "name: A\n");

        $this->client->jsonRequest('PUT', '/api/v1/yaml-templates/'.$id, [
            'name' => 'Renamed',
            'yaml' => "name: B\n",
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/yaml-templates?gameId='.$game->getId());
        $data = $this->dataList();
        self::assertCount(1, $data);
        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('Renamed', $first['name']);
        self::assertSame("name: B\n", $first['yaml']);
    }

    public function testForeignTemplateCannotBeUpdatedOrDeleted(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');

        $this->loginAs($alice);
        $id = $this->createTemplate($game->getId(), 'Alice preset', "name: A\n");

        $this->loginAs($bob);
        $this->client->jsonRequest('PUT', '/api/v1/yaml-templates/'.$id, ['name' => 'Hijacked']);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('DELETE', '/api/v1/yaml-templates/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesTemplate(): void
    {
        $user = $this->createUser('alice@example.org');
        $game = $this->createApworldReadyGame('Zelda', 'zelda');
        $this->loginAs($user);

        $id = $this->createTemplate($game->getId(), 'Preset', "name: A\n");

        $this->client->jsonRequest('DELETE', '/api/v1/yaml-templates/'.$id);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/yaml-templates?gameId='.$game->getId());
        self::assertCount(0, $this->dataList());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/yaml-templates?gameId=whatever');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAccountErasureRemovesTemplates(): void
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $registerUser);
        self::assertSame([], $registerUser->register('jean@example.org', 'correct horse battery staple', true, 'Jean')['errors']);

        $game = $this->createApworldReadyGame('Zelda', 'zelda');

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', [
            'gameId' => $game->getId(), 'name' => 'Preset', 'yaml' => "name: A\n",
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('DELETE', '/api/v1/account');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $this->entityManager->getRepository(YamlTemplate::class)->findAll());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createApworldReadyGame(string $name, string $slug): Game
    {
        $game = $this->createGame($name, $slug);
        $game->configureApworld('storage-key', 'hash', $name, "name: Player\n", new \DateTimeImmutable());
        $this->entityManager->flush();

        return $game;
    }

    private function createTemplate(string $gameId, string $name, string $yaml): string
    {
        $this->client->jsonRequest('POST', '/api/v1/yaml-templates', ['gameId' => $gameId, 'name' => $name, 'yaml' => $yaml]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<mixed>
     */
    private function dataList(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;

        return is_string($code) ? $code : null;
    }
}
