<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;

final class AdminWeeklyRunGameListTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
    }

    public function testReturnsOnlyGamesWithTemplates(): void
    {
        $alpha = $this->createGameWithApworld('Alpha', 'alpha');
        $beta = $this->createGameWithApworld('Beta', 'beta');
        // Game with no template must not appear.
        $this->createGameWithApworld('Gamma', 'gamma');

        $this->createTemplate($alpha->getId());
        $this->createTemplate($beta->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/games');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(2, $response['data']);

        // Ordered by game name ASC.
        $names = [];
        foreach ($response['data'] as $row) {
            self::assertIsArray($row);
            $names[] = $row['gameName'];
        }
        self::assertSame(['Alpha', 'Beta'], $names);
    }

    public function testCountsTemplatesAndRuns(): void
    {
        $alpha = $this->createGameWithApworld('Alpha', 'alpha');
        $beta = $this->createGameWithApworld('Beta', 'beta');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $alphaT1 = $this->createTemplate($alpha->getId());
        $alphaT2 = $this->createTemplate($alpha->getId());
        $this->createTemplate($beta->getId());

        // Alpha: 2 templates, 3 runs total (2 + 1). Beta: 1 template, 0 run.
        $this->createRun($alphaT1->getId(), 2026, 20, WeeklyRun::STATUS_ACTIVE, $now);
        $this->createRun($alphaT1->getId(), 2026, 21, WeeklyRun::STATUS_FINISHED, $now);
        $this->createRun($alphaT2->getId(), 2026, 22, WeeklyRun::STATUS_ACTIVE, $now);

        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/games');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);

        $byName = [];
        foreach ($response['data'] as $row) {
            self::assertIsArray($row);
            $gameName = $row['gameName'];
            self::assertIsString($gameName);
            $byName[$gameName] = $row;
        }

        self::assertSame(2, $byName['Alpha']['templateCount']);
        self::assertSame(3, $byName['Alpha']['runCount']);
        self::assertSame($alpha->getId(), $byName['Alpha']['gameId']);
        self::assertSame(1, $byName['Beta']['templateCount']);
        self::assertSame(0, $byName['Beta']['runCount']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/games');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNonAdminReturns403(): void
    {
        $user = $this->createUser('user@test.com', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/games');

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
            name: null,
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
}
