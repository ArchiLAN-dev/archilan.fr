<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Membership\Domain\Membership;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;

final class CurrentWeeklyRunsTest extends FunctionalTestCase
{
    private Game $game;
    private WeeklyTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = $this->createGame('Archipelago', 'archipelago');
        $this->template = $this->createTemplate($this->game->getId(), 'Test Run', null);
    }

    public function testCurrentRunsNoRunsReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(0, $data);
    }

    public function testCurrentRunsFinishedRunOnlyReturnsEmptyArray(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_FINISHED, $now);
        $run->finish($now);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(0, $data);
    }

    public function testCurrentRunsActiveRunReturnsShape(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $item = $data[0];
        self::assertIsArray($item);
        self::assertSame($run->getId(), $item['weeklyRunId']);
        self::assertSame('Test Run', $item['templateName']);
        self::assertIsString($item['yamlConfig']);
        self::assertStringContainsString('Archipelago', $item['yamlConfig']);
        self::assertSame('Archipelago', $item['gameName']);
        self::assertNull($item['coverImageUrl']);
        self::assertSame(20, $item['weekNumber']);
        self::assertSame(2026, $item['weekYear']);
        self::assertSame('active', $item['status']);
        self::assertFalse($item['isGenerated']);
        self::assertNull($item['myEntry']);

        $leaderboard = $item['leaderboard'];
        self::assertIsArray($leaderboard);
        self::assertSame([], $leaderboard['fastest']);
        self::assertSame([], $leaderboard['fewestChecks']);
        self::assertSame([], $leaderboard['fewestItems']);

        $participants = $item['participants'];
        self::assertIsArray($participants);
        self::assertCount(0, $participants);
    }

    public function testCurrentRunsIsGeneratedReflectsGeneration(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        // Not generated yet.
        $this->client->request('GET', '/api/v1/weekly-runs/current');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);
        self::assertFalse($item['isGenerated']);

        // After the orchestrator webhook stores the output key, the run is launchable.
        $run->markGenerated('sessions/weekly-gen-'.$run->getId().'/output/AP_1.zip');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/weekly-runs/current');
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);
        self::assertTrue($item['isGenerated']);
    }

    public function testCurrentRunsAuthenticatedMemberReturnsMyEntry(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        $member = $this->createUser('member@test.com', ['ROLE_USER'], 'Alice');
        $this->createMembership($member->getId());
        $entry = $this->createEntry($run->getId(), $member->getId(), 1, $now);

        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);

        $myEntry = $item['myEntry'];
        self::assertIsArray($myEntry);
        self::assertSame($entry->getId(), $myEntry['entryId']);
        self::assertNull($myEntry['connectionInfo']);
        self::assertNull($myEntry['goalReachedAt']);

        $participants = $item['participants'];
        self::assertIsArray($participants);
        self::assertCount(1, $participants);
        $firstParticipant = $participants[0];
        self::assertIsArray($firstParticipant);
        self::assertSame('Alice', $firstParticipant['displayName']);
    }

    public function testCurrentRunsUnauthenticatedMyEntryIsNull(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $this->createEntry($run->getId(), $member->getId(), 1, $now);

        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);
        self::assertNull($item['myEntry']);

        $participants = $item['participants'];
        self::assertIsArray($participants);
        self::assertCount(1, $participants);
    }

    public function testCurrentRunsLaunchedEntryReturnsConnectionInfo(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $entry = $this->createEntry($run->getId(), $member->getId(), 1, $now);
        $entry->launch('ext-session-abc', $now, ['host' => 'archipelago.archilan.fr', 'port' => 38281, 'password' => 'secret']);
        $this->entityManager->flush();

        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);

        $myEntry = $item['myEntry'];
        self::assertIsArray($myEntry);
        $connectionInfo = $myEntry['connectionInfo'];
        self::assertIsArray($connectionInfo);
        self::assertSame('archipelago.archilan.fr', $connectionInfo['host']);
        self::assertSame(38281, $connectionInfo['port']);
        self::assertSame('secret', $connectionInfo['password']);
    }

    public function testCurrentRunsWithGoalPopulatesLeaderboard(): void
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = $this->createRun($this->template->getId(), WeeklyRun::STATUS_ACTIVE, $now);

        $alice = $this->createUser('alice@test.com', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@test.com', ['ROLE_USER'], 'Bob');
        $this->createMembership($alice->getId());
        $this->createMembership($bob->getId());

        $entryAlice = $this->createEntry($run->getId(), $alice->getId(), 1, $now);
        $entryAlice->launch('ext-alice', $now, ['host' => 'h', 'port' => 1, 'password' => null]);
        $entryAlice->recordGoal($now, 3600, 50, 30);

        $entryBob = $this->createEntry($run->getId(), $bob->getId(), 1, $now);
        $entryBob->launch('ext-bob', $now, ['host' => 'h', 'port' => 2, 'password' => null]);
        $entryBob->recordGoal($now, 7200, 40, 20);

        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $item = $data[0];
        self::assertIsArray($item);
        $leaderboard = $item['leaderboard'];
        self::assertIsArray($leaderboard);

        $fastest = $leaderboard['fastest'];
        self::assertIsArray($fastest);
        self::assertCount(2, $fastest);
        $fastestFirst = $fastest[0];
        self::assertIsArray($fastestFirst);
        self::assertSame($entryAlice->getId(), $fastestFirst['entryId']);
        self::assertSame(3600, $fastestFirst['completionTimeSeconds']);

        $fewestChecks = $leaderboard['fewestChecks'];
        self::assertIsArray($fewestChecks);
        self::assertCount(2, $fewestChecks);
        $fewestChecksFirst = $fewestChecks[0];
        self::assertIsArray($fewestChecksFirst);
        self::assertSame($entryBob->getId(), $fewestChecksFirst['entryId']);

        $fewestItems = $leaderboard['fewestItems'];
        self::assertIsArray($fewestItems);
        self::assertCount(2, $fewestItems);
        $fewestItemsFirst = $fewestItems[0];
        self::assertIsArray($fewestItemsFirst);
        self::assertSame($entryBob->getId(), $fewestItemsFirst['entryId']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function createTemplate(string $gameId, ?string $name, ?int $maxAttempts): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: $name,
            maxAttempts: $maxAttempts,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $template;
    }

    private function createRun(string $templateId, string $status, \DateTimeImmutable $startedAt): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: $status,
            startedAt: $startedAt,
            createdAt: $now,
        );
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

    private function createMembership(string $userId): Membership
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $membership = Membership::create(
            $userId,
            $now,
            new \DateTimeImmutable('2027-05-01T10:00:00+00:00'),
            'admin',
            null,
            null,
            $now,
        );
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $membership;
    }
}
