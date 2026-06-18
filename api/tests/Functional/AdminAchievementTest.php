<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\DefaultAchievementDefinitions;

final class AdminAchievementTest extends FunctionalTestCase
{
    public function testListRequiresAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org'));
        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsDefinitionsAndFormOptions(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        self::assertResponseIsSuccessful();

        $json = $this->decodedJsonResponse();
        self::assertIsArray($json['data']);
        self::assertCount(count(DefaultAchievementDefinitions::all()), $json['data']);
        self::assertIsArray($json['meta']);
        $options = $json['meta']['options'] ?? null;
        self::assertIsArray($options);
        self::assertNotEmpty($options['facts']);
        $operators = $options['operators'] ?? null;
        self::assertIsArray($operators);
        self::assertContains('between', $operators);
    }

    public function testCreateUpdateToggleAndReorder(): void
    {
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        // Create
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', [
            'key' => 'night_owl',
            'name' => 'Couche-tard',
            'description' => 'Jouer la nuit.',
            'rule' => ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 5]]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->dataArray()['id'] ?? null;
        self::assertIsString($id);

        // Update keeps the key immutable
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievements/'.$id, [
            'key' => 'ignored',
            'name' => 'Oiseau de nuit',
            'rule' => ['op' => 'any', 'rules' => [['fact' => 'goals', 'operator' => '>=', 'value' => 3]]],
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->dataArray();
        self::assertSame('night_owl', $updated['key']);
        self::assertSame('Oiseau de nuit', $updated['name']);

        // Toggle active
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$id.'/active', ['active' => false]);
        self::assertResponseStatusCodeSame(204);

        // Reorder is a no-op-safe 204
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/reorder', ['ids' => [$id]]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testCreateRejectsInvalidRuleWith422(): void
    {
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', [
            'key' => 'broken',
            'name' => 'Cassé',
            'rule' => ['op' => 'all', 'rules' => []],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsDuplicateKeyWith422(): void
    {
        $this->seedDefaultAchievementDefinitions();
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', [
            'key' => 'first_run',
            'name' => 'Doublon',
            'rule' => ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1]]],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<mixed>
     */
    private function dataArray(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
