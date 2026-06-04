<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminWeeklyTemplateTest extends FunctionalTestCase
{
    private User $admin;
    private Game $game;

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

        $this->admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $this->game = $this->createGameWithApworld('Archipelago', 'archipelago');
    }

    // ── LIST ──────────────────────────────────────────────────────────────────

    public function testListReturnsTemplates(): void
    {
        $this->createTemplate($this->game->getId(), 'Template A');
        $this->createTemplate($this->game->getId(), 'Template B');
        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(2, $response['data']);
        self::assertIsArray($response['meta']);
        self::assertSame(2, $response['meta']['total']);
    }

    public function testListUnauthenticatedReturns401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates');

        self::assertResponseStatusCodeSame(401);
    }

    public function testListNonAdminReturns403(): void
    {
        $user = $this->createUser('user@test.com', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-templates');

        self::assertResponseStatusCodeSame(403);
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public function testCreateReturnsTemplate(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/weekly-templates', [
            'gameId' => $this->game->getId(),
            'yamlConfig' => "name: ArchiLAN\ngame: Archipelago\n",
            'name' => 'My Template',
            'maxAttempts' => 3,
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('My Template', $data['name']);
        self::assertSame($this->game->getId(), $data['gameId']);
        self::assertSame(3, $data['maxAttempts']);
        self::assertTrue($data['isActive']);
    }

    public function testCreateGameNotReadyReturns422(): void
    {
        $gameNoApworld = $this->createGame('NoApworld', 'no-apworld');
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/weekly-templates', [
            'gameId' => $gameNoApworld->getId(),
            'yamlConfig' => "name: ArchiLAN\n",
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('game_not_ready', $response['error']);
    }

    public function testCreateMissingGameIdReturns422(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/weekly-templates', [
            'yamlConfig' => "name: ArchiLAN\n",
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function testUpdatePatchesFields(): void
    {
        $template = $this->createTemplate($this->game->getId(), 'Original Name');
        $this->loginAs($this->admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/weekly-templates/'.$template->getId(), [
            'name' => 'Updated Name',
            'maxAttempts' => 5,
        ]);

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('Updated Name', $data['name']);
        self::assertSame(5, $data['maxAttempts']);
    }

    public function testUpdateNotFoundReturns404(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/weekly-templates/nonexistent-id', [
            'name' => 'New Name',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── DEACTIVATE ────────────────────────────────────────────────────────────

    public function testDeactivateReturns204(): void
    {
        $template = $this->createTemplate($this->game->getId(), 'Active Template');
        $this->loginAs($this->admin);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/weekly-templates/'.$template->getId());

        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(WeeklyTemplate::class, $template->getId());
        self::assertInstanceOf(WeeklyTemplate::class, $refreshed);
        self::assertFalse($refreshed->isActive());
    }

    public function testDeactivateNotFoundReturns404(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/weekly-templates/nonexistent-id');

        self::assertResponseStatusCodeSame(404);
    }

    // ── ADMIN CURRENT RUNS ────────────────────────────────────────────────────

    public function testAdminCurrentRunsReturnsActiveAndFinishedForCurrentWeek(): void
    {
        $template = $this->createTemplate($this->game->getId(), 'Weekly Template');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');

        $this->createRun($template->getId(), $weekYear, $weekNumber, WeeklyRun::STATUS_ACTIVE, $now);
        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/current');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        $run = $response['data'][0];
        self::assertIsArray($run);
        self::assertSame('active', $run['status']);
        self::assertSame('Weekly Template', $run['templateName']);
    }

    public function testAdminCurrentRunsExcludesOtherWeeks(): void
    {
        $template = $this->createTemplate($this->game->getId(), 'Old Template');
        $old = new \DateTimeImmutable('2020-01-06T00:00:00+00:00');
        $this->createRun($template->getId(), 2020, 2, WeeklyRun::STATUS_ACTIVE, $old);
        $this->loginAs($this->admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/weekly-runs/current');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(0, $response['data']);
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

    private function createTemplate(string $gameId, ?string $name = null): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: $name,
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
