<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Membership\Domain\Membership;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Infrastructure\SpyWeeklyRunnerGateway;
use Doctrine\ORM\Tools\SchemaTool;

final class WeeklyRunLaunchTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Membership::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(WeeklyTemplate::class),
            $this->entityManager->getClassMetadata(WeeklyRun::class),
            $this->entityManager->getClassMetadata(WeeklyEntry::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->spy()->reset();
    }

    public function testLaunchFromPreGeneratedSeedSucceeds(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER'], displayName: 'SkyPlayer');
        $this->createMembership($member->getId());
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\ngame: Archipelago\n");
        $run = $this->createRunWithSeed($template->getId());
        $entry = $this->createEntry($run->getId(), $member->getId());

        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$run->getId().'/entries/'.$entry->getId().'/launch');

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame($entry->getId(), $response['data']['entryId']);

        $spy = $this->spy();
        self::assertCount(1, $spy->launchCalls);
        $call = $spy->launchCalls[0];
        self::assertSame($entry->getId(), $call['entryId']);
        self::assertSame('abc123apworldhash', $call['apworldHash']);

        $this->client->jsonRequest('GET', '/api/v1/weekly-runs/current');
        self::assertResponseIsSuccessful();
        $currentResponse = $this->decodedJsonResponse();
        $currentData = $currentResponse['data'];
        self::assertIsArray($currentData);
        $firstRun = $currentData[0];
        self::assertIsArray($firstRun);
        $myEntry = $firstRun['myEntry'];
        self::assertIsArray($myEntry);
        $connectionInfo = $myEntry['connectionInfo'];
        self::assertIsArray($connectionInfo);
        self::assertSame('runner.test', $connectionInfo['host']);
        self::assertSame(38281, $connectionInfo['port']);
    }

    public function testLaunchThrowsWhenRunNotGenerated(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\n");
        $run = $this->createRun($template->getId());
        $entry = $this->createEntry($run->getId(), $member->getId());

        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$run->getId().'/entries/'.$entry->getId().'/launch');

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('run_not_generated', $response['error']);
    }

    public function testLaunchAlreadyLaunchedReturns422(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\ngame: Archipelago\n");
        $run = $this->createRunWithSeed($template->getId());
        $now = new \DateTimeImmutable('2026-05-11T10:00:00+00:00');
        $entry = $this->createEntry($run->getId(), $member->getId(), launchedWith: 'session-abc', launchedAt: $now);

        $this->loginAs($member);

        $this->client->jsonRequest('POST', '/api/v1/weekly-runs/'.$run->getId().'/entries/'.$entry->getId().'/launch');

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertSame('session_already_started', $response['error']);
    }

    public function testCurrentReturnsMyEntryWhenAuthenticated(): void
    {
        $member = $this->createUser('member@test.com', ['ROLE_USER']);
        $this->createMembership($member->getId());
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\n");
        $run = $this->createRun($template->getId());
        $this->createEntry($run->getId(), $member->getId());

        $this->loginAs($member);

        $this->client->jsonRequest('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        $runData = $response['data'][0];
        self::assertIsArray($runData);
        self::assertArrayHasKey('myEntry', $runData);
        self::assertIsArray($runData['myEntry']);
        self::assertArrayHasKey('entryId', $runData['myEntry']);
    }

    public function testCurrentReturnsNullMyEntryWhenAnonymous(): void
    {
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\n");
        $this->createRun($template->getId());

        $this->client->jsonRequest('GET', '/api/v1/weekly-runs/current');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        $runData = $response['data'][0];
        self::assertIsArray($runData);
        self::assertArrayHasKey('myEntry', $runData);
        self::assertNull($runData['myEntry']);
    }

    public function testLeaderboardReturnsSortedArrays(): void
    {
        $game = $this->createGame('Archipelago', 'archipelago');
        $template = $this->createTemplate($game->getId(), "name: ArchiLAN\n");
        $run = $this->createRun($template->getId());

        $user1 = $this->createUser('user1@test.com', ['ROLE_USER'], displayName: 'Fast');
        $user2 = $this->createUser('user2@test.com', ['ROLE_USER'], displayName: 'FewerChecks');

        $this->createEntryWithGoal($run->getId(), $user1->getId(), completionTime: 1000, checks: 50, items: 80);
        $this->createEntryWithGoal($run->getId(), $user2->getId(), completionTime: 2000, checks: 30, items: 60);

        $this->client->jsonRequest('GET', '/api/v1/weekly-runs/'.$run->getId().'/leaderboard');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        $data = $response['data'];

        $fastest = $data['fastest'];
        self::assertIsArray($fastest);
        self::assertCount(2, $fastest);
        $fastestFirst = $fastest[0];
        self::assertIsArray($fastestFirst);
        self::assertSame(1000, $fastestFirst['completionTimeSeconds']);

        $fewestChecks = $data['fewestChecks'];
        self::assertIsArray($fewestChecks);
        self::assertCount(2, $fewestChecks);
        $fewestChecksFirst = $fewestChecks[0];
        self::assertIsArray($fewestChecksFirst);
        self::assertSame(30, $fewestChecksFirst['checksTotal']);

        $fewestItems = $data['fewestItems'];
        self::assertIsArray($fewestItems);
        self::assertCount(2, $fewestItems);
        $fewestItemsFirst = $fewestItems[0];
        self::assertIsArray($fewestItemsFirst);
        self::assertSame(60, $fewestItemsFirst['itemsTotal']);

        $participants = $data['participants'];
        self::assertIsArray($participants);
        self::assertCount(2, $participants);
    }

    private function createTemplate(string $gameId, string $yamlConfig): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: $yamlConfig,
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

    private function createRun(string $templateId): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
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

    private function createRunWithSeed(string $templateId): WeeklyRun
    {
        $run = $this->createRun($templateId);
        $run->markGenerated('abc123apworldhash');
        $this->entityManager->flush();

        return $run;
    }

    private function createEntry(
        string $runId,
        string $userId,
        ?string $launchedWith = null,
        ?\DateTimeImmutable $launchedAt = null,
    ): WeeklyEntry {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $runId,
            userId: $userId,
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
            externalSessionId: $launchedWith,
            launchedAt: $launchedAt,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function createEntryWithGoal(
        string $runId,
        string $userId,
        int $completionTime,
        int $checks,
        int $items,
    ): WeeklyEntry {
        $now = new \DateTimeImmutable('2026-05-11T00:00:00+00:00');
        $goalAt = new \DateTimeImmutable('2026-05-12T00:00:00+00:00');
        $entry = new WeeklyEntry(
            id: bin2hex(random_bytes(8)),
            weeklyRunId: $runId,
            userId: $userId,
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
            externalSessionId: 'session-'.bin2hex(random_bytes(4)),
            launchedAt: $now,
            goalReachedAt: $goalAt,
            completionTimeSeconds: $completionTime,
            checksTotal: $checks,
            itemsTotal: $items,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function spy(): SpyWeeklyRunnerGateway
    {
        $spy = static::getContainer()->get(SpyWeeklyRunnerGateway::class);
        self::assertInstanceOf(SpyWeeklyRunnerGateway::class, $spy);

        return $spy;
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
