<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\ActivityEntry;
use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\WeeklyRuns\Application\Handler\StopWeeklyRunsMessageHandler;
use App\WeeklyRuns\Application\Message\StopWeeklyRunsMessage;
use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;

/**
 * Story 43.15: duels between friends on a weekly run.
 */
final class WeeklyDuelTest extends FunctionalTestCase
{
    private User $viewer;

    private WeeklyRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $now = new \DateTimeImmutable('-1 day');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $game->getId(),
            yamlConfig: "name: ArchiLAN\ngame: Hollow Knight\n",
            name: 'Hebdo HK',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $this->run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $template->getId(),
            weekYear: 2026,
            weekNumber: 41,
            seed: 'archilan-weekly-2026-41',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
    }

    public function testAChallengedFriendAcceptsAndTheDuelShowsItsRanking(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');
        $stranger = $this->createUser('stranger@example.org', displayName: 'Stranger', slug: 'stranger');

        $duelId = $this->challenge([$alice->getId(), $bob->getId(), $stranger->getId()]);
        self::assertResponseStatusCodeSame(201);

        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['type' => 'weekly_duel']);
        self::assertCount(2, $notices, 'the stranger is left out');
        self::assertSame('Hollow Knight', $notices[0]->getPayload()['gameName'] ?? null);

        $this->loginAs($alice);
        $this->client->request('POST', '/api/v1/weekly-duels/'.$duelId.'/accept');
        self::assertResponseStatusCodeSame(200);
        $this->loginAs($bob);
        $this->client->request('POST', '/api/v1/weekly-duels/'.$duelId.'/decline');
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', '/api/v1/weekly-duels/'.$duelId.'/accept');
        self::assertResponseStatusCodeSame(409, 'already answered');
        self::assertSame([], $this->duelsOf($bob));

        $this->entry($alice, 900);
        $this->entry($this->viewer, null);
        $this->entityManager->flush();

        $duels = $this->duelsOf($this->viewer);
        self::assertCount(1, $duels);
        $duel = $duels[0];
        self::assertIsArray($duel);
        self::assertTrue($duel['isCreator']);
        self::assertSame('Hollow Knight', $duel['gameName']);
        $standings = $duel['standings'];
        self::assertIsArray($standings);
        self::assertSame([['alice', 'goal', 900], ['viewer', 'launched', null]], array_map(
            static fn (mixed $row): array => is_array($row) ? [$row['slug'], $row['status'], $row['completionTimeSeconds']] : [],
            $standings,
        ));
    }

    public function testTheBestTimeAtTheGoalWinsWhenTheWeekEnds(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');
        $duel = $this->duel([$alice, $bob]);
        $this->entry($this->viewer, 1500);
        $this->entry($alice, 780);
        $this->entry($bob, null);
        $this->entityManager->flush();

        $this->endTheWeek();

        $resolved = $this->entityManager->getRepository(WeeklyDuel::class)->find($duel->getId());
        self::assertInstanceOf(WeeklyDuel::class, $resolved);
        self::assertTrue($resolved->isResolved());
        self::assertSame($alice->getId(), $resolved->getWinnerId());

        $won = $this->duelResult($alice);
        self::assertSame('won', $won['outcome'] ?? null);
        self::assertSame('Viewer', $won['opponentName'] ?? null);
        self::assertSame(720, $won['marginSeconds'] ?? null);
        $lost = $this->duelResult($this->viewer);
        self::assertSame('lost', $lost['outcome'] ?? null);
        self::assertSame(720, $lost['marginSeconds'] ?? null);
        self::assertNull($this->duelResult($bob)['marginSeconds'] ?? null, 'no goal, no margin');

        $activity = $this->entityManager->getRepository(ActivityEntry::class)->findBy(['type' => ActivityEntry::TYPE_WEEKLY_DUEL]);
        self::assertCount(1, $activity);
        self::assertSame($alice->getId(), $activity[0]->getActorId());
        self::assertSame($this->viewer->getId(), $activity[0]->getPayload()['withUserId'] ?? null);

        $this->loginAs($this->viewer);
        self::assertSame([], $this->duelsOf($this->viewer), 'a resolved duel leaves the page');
    }

    public function testNobodyAtTheGoalMeansNoWinner(): void
    {
        $alice = $this->friend('alice');
        $duel = $this->duel([$alice]);
        $this->entry($alice, null);
        $this->entityManager->flush();

        $this->endTheWeek();

        $resolved = $this->entityManager->getRepository(WeeklyDuel::class)->find($duel->getId());
        self::assertInstanceOf(WeeklyDuel::class, $resolved);
        self::assertTrue($resolved->isResolved());
        self::assertNull($resolved->getWinnerId());
        self::assertSame('none', $this->duelResult($alice)['outcome'] ?? null);
        self::assertSame([], $this->entityManager->getRepository(ActivityEntry::class)->findBy(['type' => ActivityEntry::TYPE_WEEKLY_DUEL]));
    }

    public function testABlockCancelsTheDuelForThePair(): void
    {
        $alice = $this->friend('alice');
        $bob = $this->friend('bob');
        $duel = $this->duel([$alice, $bob]);
        // Two challenged friends fall out: the blocked one leaves, the creator's duel goes on.
        $this->entityManager->persist(Block::create($alice->getId(), $bob->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertSame([], $this->duelsOf($bob));
        $duels = $this->duelsOf($alice);
        self::assertCount(1, $duels);

        $this->entityManager->clear();
        $bobIn = $this->entityManager->getRepository(WeeklyDuelParticipant::class)->findOneBy(['duelId' => $duel->getId(), 'userId' => $bob->getId()]);
        self::assertInstanceOf(WeeklyDuelParticipant::class, $bobIn);
        self::assertSame(WeeklyDuelParticipant::CANCELLED, $bobIn->getStatus());
    }

    public function testDuelsHaveCaps(): void
    {
        $friends = array_map($this->friend(...), ['a1', 'a2', 'a3', 'a4', 'a5', 'a6']);

        $this->challenge(array_map(static fn (User $u): string => $u->getId(), $friends));
        self::assertResponseStatusCodeSame(422, 'six friends is one too many');

        for ($i = 0; $i < WeeklyDuel::MAX_PER_WEEK; ++$i) {
            $this->challenge([$friends[0]->getId()]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->challenge([$friends[0]->getId()]);
        self::assertResponseStatusCodeSame(429);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function friend(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', displayName: ucfirst($slug), slug: $slug);
        $friendship = Friendship::request($user->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param list<string> $userIds
     */
    private function challenge(array $userIds): string
    {
        $this->loginAs($this->viewer);
        $this->client->request('POST', '/api/v1/weekly-runs/'.$this->run->getId().'/duels', content: json_encode(['userIds' => $userIds], \JSON_THROW_ON_ERROR));
        $data = $this->decodedJsonResponse()['data'] ?? null;

        return is_array($data) && is_string($data['duelId'] ?? null) ? $data['duelId'] : '';
    }

    /**
     * A duel created by the viewer, every challenged friend having accepted.
     *
     * @param list<User> $opponents
     */
    private function duel(array $opponents): WeeklyDuel
    {
        $now = new \DateTimeImmutable();
        $duel = WeeklyDuel::open($this->run->getId(), $this->viewer->getId(), $now);
        $this->entityManager->persist($duel);
        $this->entityManager->persist(WeeklyDuelParticipant::creator($duel, $now));
        foreach ($opponents as $opponent) {
            $participant = WeeklyDuelParticipant::challenge($duel, $opponent->getId(), $now);
            $participant->accept($now);
            $this->entityManager->persist($participant);
        }
        $this->entityManager->flush();

        return $duel;
    }

    private function entry(User $user, ?int $seconds): void
    {
        $launchedAt = new \DateTimeImmutable('-12 hours');
        $entry = new WeeklyEntry(bin2hex(random_bytes(16)), $this->run->getId(), $user->getId(), 1, $launchedAt, $launchedAt);
        $entry->launch(bin2hex(random_bytes(16)), $launchedAt, ['host' => 'bridge.local', 'port' => 38281, 'password' => null]);
        if (null !== $seconds) {
            $entry->recordGoal($launchedAt->modify('+'.$seconds.' seconds'), $seconds, 40, 20);
        }
        $this->entityManager->persist($entry);
    }

    private function endTheWeek(): void
    {
        $handler = self::getContainer()->get(StopWeeklyRunsMessageHandler::class);
        self::assertInstanceOf(StopWeeklyRunsMessageHandler::class, $handler);
        $handler(new StopWeeklyRunsMessage());
    }

    /** @return list<mixed> */
    private function duelsOf(User $member): array
    {
        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/weekly-duels?weeklyRun='.$this->run->getId());
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }

    /** @return array<string, mixed> */
    private function duelResult(User $member): array
    {
        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $member->getId(), 'type' => 'weekly_duel_result']);
        self::assertCount(1, $notices);

        return $notices[0]->getPayload();
    }
}
