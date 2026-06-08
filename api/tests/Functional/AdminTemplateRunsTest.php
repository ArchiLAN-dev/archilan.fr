<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;

final class AdminTemplateRunsTest extends FunctionalTestCase
{
    private User $admin;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $this->game = $this->createGameWithApworld('Archipelago', 'archipelago');
    }

    public function testReturnsRunsForTemplateOrderedByWeekDesc(): void
    {
        $template = $this->createTemplate($this->game->getId());
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');

        $older = $this->createRun($template->getId(), 2026, 19, WeeklyRun::STATUS_FINISHED, $now);
        $newer = $this->createRun($template->getId(), 2026, 21, WeeklyRun::STATUS_ACTIVE, $now);
        $this->createEntry($newer->getId(), $this->admin->getId(), 1, $now);

        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates/'.$template->getId().'/runs');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(2, $response['data']);

        // Most recent week first.
        $first = $response['data'][0];
        self::assertIsArray($first);
        self::assertSame($newer->getId(), $first['weeklyRunId']);
        self::assertSame(21, $first['weekNumber']);
        self::assertSame(2026, $first['weekYear']);
        self::assertSame('active', $first['status']);
        self::assertFalse($first['hasOutput']);
        self::assertSame(1, $first['entryCount']);

        $second = $response['data'][1];
        self::assertIsArray($second);
        self::assertSame($older->getId(), $second['weeklyRunId']);
        self::assertSame(0, $second['entryCount']);
    }

    public function testUnknownTemplateReturnsEmptyData(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates/nonexistent-id/runs');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(0, $response['data']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates/some-id/runs');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminReturns403(): void
    {
        $user = $this->createUser('user@test.com', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates/some-id/runs');

        self::assertResponseStatusCodeSame(403);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function createGameWithApworld(string $name, string $slug): Game
    {
        $game = $this->createGame($name, $slug);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE game SET apworld_storage_key = :key WHERE id = :id',
            ['key' => 'apworlds/'.$slug.'.apworld', 'id' => $game->getId()],
        );

        $this->entityManager->refresh($game);

        return $game;
    }

    private function createTemplate(string $gameId): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Weekly Template',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    private function createRun(
        string $templateId,
        int $weekYear,
        int $weekNumber,
        string $status,
        \DateTimeImmutable $startedAt,
    ): WeeklyRun {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
            weekYear: $weekYear,
            weekNumber: $weekNumber,
            seed: 'archilan-weekly-'.$weekYear.'-'.$weekNumber,
            status: $status,
            startedAt: $startedAt,
            createdAt: $now,
        );
        if (WeeklyRun::STATUS_FINISHED === $status) {
            $run->finish($now);
        }
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function createEntry(
        string $runId,
        string $userId,
        int $attemptNumber,
        \DateTimeImmutable $createdAt,
    ): WeeklyEntry {
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $runId,
            userId: $userId,
            attemptNumber: $attemptNumber,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
