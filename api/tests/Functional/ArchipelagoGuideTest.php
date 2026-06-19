<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGuide;

final class ArchipelagoGuideTest extends FunctionalTestCase
{
    public function testPublicReadDropsStepsWithUnknownType(): void
    {
        $guide = ArchipelagoGuide::create([
            ['type' => 'bogus', 'title' => 'Stale', 'description' => '', 'links' => []],
            ['type' => 'client', 'title' => 'Installer le launcher', 'description' => '', 'links' => []],
        ], new \DateTimeImmutable('2026-06-19T10:00:00+00:00'));
        $this->entityManager->persist($guide);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/archipelago-guide');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['steps']);
        self::assertCount(1, $data['steps']);
        $step = $data['steps'][0];
        self::assertIsArray($step);
        self::assertSame('client', $step['type']);
    }

    public function testPublicGetReturnsEmptyWhenUnset(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/archipelago-guide');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame([], $data['steps']);
    }

    public function testAdminUpdatesThenPublicReturnsSteps(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-guide', [
            'steps' => [
                ['type' => 'client', 'title' => 'Installer le launcher', 'description' => 'desc', 'links' => [
                    ['label' => 'Téléchargement', 'url' => 'https://github.com/ArchipelagoMW/Archipelago/releases'],
                ]],
            ],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/archipelago-guide');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['steps']);
        self::assertCount(1, $data['steps']);
        $step = $data['steps'][0];
        self::assertIsArray($step);
        self::assertSame('client', $step['type']);
    }

    public function testAdminUpdateRejectsInvalidStep(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-guide', [
            'steps' => [['type' => 'bogus', 'title' => 'x', 'links' => []]],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminUpdateRequiresAdmin(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-guide', ['steps' => []]);
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-guide', ['steps' => []]);
        self::assertResponseStatusCodeSame(403);
    }
}
