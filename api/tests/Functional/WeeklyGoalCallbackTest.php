<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Realtime\Infrastructure\SpyHub;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\ORM\Tools\SchemaTool;

final class WeeklyGoalCallbackTest extends FunctionalTestCase
{
    private const GOOD_SECRET = 'test-runner-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(WeeklyTemplate::class),
            $this->entityManager->getClassMetadata(WeeklyRun::class),
            $this->entityManager->getClassMetadata(WeeklyEntry::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->hub()->reset();
    }

    public function testCallback_badSecret_returns401(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/weekly-runs/goal-callback',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'wrong-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['externalSessionId' => 'abc', 'checksTotal' => 1, 'itemsTotal' => 1, 'goalReachedAt' => '2026-05-12T10:00:00+00:00'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallback_missingSecret_returns401(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/weekly-runs/goal-callback',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['externalSessionId' => 'abc', 'checksTotal' => 1, 'itemsTotal' => 1, 'goalReachedAt' => '2026-05-12T10:00:00+00:00'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallback_unknownSessionId_returns200(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/weekly-runs/goal-callback',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => self::GOOD_SECRET, 'CONTENT_TYPE' => 'application/json'],
            json_encode(['externalSessionId' => 'nonexistent', 'checksTotal' => 10, 'itemsTotal' => 20, 'goalReachedAt' => '2026-05-12T10:00:00+00:00'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testCallback_recordsGoalAndPublishesMercure(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER'], displayName: 'GoalPlayer');
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId());
        $startedAt = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($template->getId(), $startedAt);
        $entry = $this->createEntry($run->getId(), $member->getId(), 'session-goal-test');

        $goalReachedAt = '2026-05-12T10:00:00+00:00';

        $this->client->request(
            'POST',
            '/api/v1/internal/weekly-runs/goal-callback',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => self::GOOD_SECRET, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'externalSessionId' => 'session-goal-test',
                'checksTotal' => 42,
                'itemsTotal' => 87,
                'goalReachedAt' => $goalReachedAt,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame($entry->getId(), $response['data']['entryId']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(WeeklyEntry::class, $entry->getId());
        self::assertInstanceOf(WeeklyEntry::class, $refreshed);
        self::assertNotNull($refreshed->getGoalReachedAt());
        self::assertSame(42, $refreshed->getChecksTotal());
        self::assertSame(87, $refreshed->getItemsTotal());

        $published = $this->hub()->published;
        self::assertCount(1, $published);
        $update = $published[0];
        self::assertContains('weekly-runs/'.$run->getId().'/leaderboard', $update->getTopics());
        $eventData = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($eventData);
        self::assertSame('goal_reached', $eventData['event']);
        self::assertSame(42, $eventData['checksTotal']);
    }

    public function testCallback_idempotent_secondCallIsNoOp(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId());
        $startedAt = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($template->getId(), $startedAt);
        $this->createEntry($run->getId(), $member->getId(), 'session-idem-test');

        $payload = json_encode([
            'externalSessionId' => 'session-idem-test',
            'checksTotal' => 10,
            'itemsTotal' => 20,
            'goalReachedAt' => '2026-05-12T10:00:00+00:00',
        ], JSON_THROW_ON_ERROR);

        $headers = ['HTTP_X_INTERNAL_SECRET' => self::GOOD_SECRET, 'CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/v1/internal/weekly-runs/goal-callback', [], [], $headers, $payload);
        self::assertResponseStatusCodeSame(200);

        $this->hub()->reset();

        $this->client->request('POST', '/api/v1/internal/weekly-runs/goal-callback', [], [], $headers, $payload);
        self::assertResponseStatusCodeSame(200);

        self::assertCount(0, $this->hub()->published);
    }

    private function hub(): SpyHub
    {
        $hub = static::getContainer()->get(SpyHub::class);
        self::assertInstanceOf(SpyHub::class, $hub);

        return $hub;
    }

    private function createTemplate(string $gameId): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Test Template',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    private function createRun(string $templateId, \DateTimeImmutable $startedAt): WeeklyRun
    {
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $startedAt,
            createdAt: $startedAt,
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function createEntry(
        string $runId,
        string $userId,
        string $externalSessionId,
    ): WeeklyEntry {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $runId,
            userId: $userId,
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
            externalSessionId: $externalSessionId,
            launchedAt: $now,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
