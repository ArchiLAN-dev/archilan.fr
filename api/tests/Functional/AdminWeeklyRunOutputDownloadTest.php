<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\NullMinioStorage;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;

final class AdminWeeklyRunOutputDownloadTest extends FunctionalTestCase
{
    private const SESSIONS_BUCKET = 'sessions';

    private User $admin;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        NullMinioStorage::reset();
        $this->admin = $this->createUser('admin@test.com', ['ROLE_ADMIN']);
        $this->game = $this->createGameWithApworld('Archipelago', 'archipelago');
    }

    public function testDownloadsGeneratedSeed(): void
    {
        $run = $this->createGeneratedRun('sessions/weekly-gen-run1/output/AP_42.zip', 'SEED-BYTES');
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/weekly-runs/'.$run->getId().'/output');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertIsString($disposition);
        self::assertStringContainsString('attachment', $disposition);
        // Full-output zip artifacts get a readable download name.
        self::assertStringContainsString('weekly-run-'.$run->getId().'.zip', $disposition);
    }

    public function testReturns404WhenRunNotGenerated(): void
    {
        $run = $this->createRun(); // no markGenerated
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/weekly-runs/'.$run->getId().'/output');

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns404WhenObjectMissingFromStorage(): void
    {
        // Run references an output key, but nothing was uploaded to MinIO.
        $run = $this->createRun();
        $run->markGenerated('sessions/weekly-gen-run1/output/missing.zip');
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/weekly-runs/'.$run->getId().'/output');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminReturns403(): void
    {
        $run = $this->createRun();
        $user = $this->createUser('user@test.com', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->request('GET', '/api/v1/admin/weekly-runs/'.$run->getId().'/output');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->request('GET', '/api/v1/admin/weekly-runs/some-id/output');

        self::assertResponseStatusCodeSame(401);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function createGeneratedRun(string $outputKey, string $contents): WeeklyRun
    {
        $run = $this->createRun();
        $run->markGenerated($outputKey);
        $this->entityManager->flush();

        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);
        $storage->upload(self::SESSIONS_BUCKET, $outputKey, $contents);

        return $run;
    }

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

    private function createRun(): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');

        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $this->game->getId(),
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Weekly',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);

        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $template->getId(),
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }
}
