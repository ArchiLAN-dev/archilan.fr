<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;

final class AdminSessionConfigTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function serverSection(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $config = $data['config'] ?? null;
        self::assertIsArray($config);
        $server = $config['server'] ?? null;
        self::assertIsArray($server);

        return $server;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function generationSection(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $config = $data['config'] ?? null;
        self::assertIsArray($config);
        $generation = $config['generation'] ?? null;
        self::assertIsArray($generation);

        return $generation;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function overrideData(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $override = $data['override'] ?? null;
        self::assertIsArray($override);

        return $override;
    }

    public function testGetReturnsDefaultsForWeekly(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/weekly');

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('weekly', $data['type']);

        $server = $this->serverSection();
        self::assertSame('disabled', $server['releaseMode']);
        self::assertTrue($server['disableItemCheat']);
    }

    public function testGetUnknownTypeIs404(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/nope');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetRequiresAdmin(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/weekly');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPutPersistsAndGetReflectsIt(): void
    {
        $this->loginAs($this->admin);

        $payload = [
            'server' => [
                'releaseMode' => 'goal',
                'collectMode' => 'disabled',
                'remainingMode' => 'goal',
                'disableItemCheat' => false,
                'hintCost' => 25,
                'locationCheckPoints' => 2,
                'countdownMode' => 'auto',
                'autoShutdown' => 0,
                'compatibility' => 0,
                'joinPassword' => null,
            ],
            'generation' => [
                'plandoOptions' => ['bosses'],
                'race' => true,
                'spoiler' => 0,
            ],
        ];

        $this->client->jsonRequest('PUT', '/api/v1/admin/session-config/event', $payload);
        self::assertResponseStatusCodeSame(200);
        $server = $this->serverSection();
        self::assertSame('goal', $server['releaseMode']);
        self::assertSame(25, $server['hintCost']);

        // Persisted: a fresh GET returns the updated values.
        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/event');
        self::assertResponseStatusCodeSame(200);
        $server = $this->serverSection();
        $generation = $this->generationSection();
        self::assertSame('goal', $server['releaseMode']);
        self::assertSame(0, $generation['spoiler']);
        self::assertTrue($generation['race']);
    }

    public function testOverridePutGetDeleteCycle(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/session-config/override/tpl-1', [
            'releaseMode' => 'goal',
            'hintCost' => 5,
        ]);
        self::assertResponseStatusCodeSame(200);
        $override = $this->overrideData();
        self::assertSame('goal', $override['releaseMode']);
        self::assertSame(5, $override['hintCost']);

        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/override/tpl-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('goal', $this->overrideData()['releaseMode']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/session-config/override/tpl-1');
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/override/tpl-1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->overrideData());
    }

    public function testOverridePutRejectsInvalidWith422(): void
    {
        $this->loginAs($this->admin);
        $this->client->jsonRequest('PUT', '/api/v1/admin/session-config/override/tpl-1', ['spoiler' => 9]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOverrideRequiresAdmin(): void
    {
        $member = $this->createUser('member2@test.com', ['ROLE_USER']);
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/admin/session-config/override/tpl-1');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPutRejectsInvalidEnumWith422(): void
    {
        $this->loginAs($this->admin);

        $payload = [
            'server' => [
                'releaseMode' => 'nonsense',
                'collectMode' => 'disabled',
                'remainingMode' => 'goal',
                'disableItemCheat' => true,
                'hintCost' => 10,
                'locationCheckPoints' => 1,
                'countdownMode' => 'auto',
                'autoShutdown' => 0,
                'compatibility' => 2,
                'joinPassword' => null,
            ],
            'generation' => ['plandoOptions' => [], 'race' => false, 'spoiler' => 3],
        ];

        $this->client->jsonRequest('PUT', '/api/v1/admin/session-config/weekly', $payload);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('invalid_release_collect_mode', $error['code']);
    }
}
