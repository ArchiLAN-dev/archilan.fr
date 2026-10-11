<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 43.8: the community leaderboard among friends, and « Tes amis cette semaine » on a weekly run.
 */
final class FriendsLeaderboardTest extends FunctionalTestCase
{
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
    }

    public function testTheFriendsBoardRanksTheViewerAndFriendsAmongThemselves(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent('LAN', $now, $now->modify('+1 day'));
        $game = $this->createGame('G', 'g');
        $session = Session::createRunning(bin2hex(random_bytes(16)), $event->getId(), 'bridge.local', 38281, 'secret', 5000, $now);
        $session->transition(Session::STATUS_FINISHED, $now->modify('+2 hours'));
        $this->entityManager->persist($session);

        $champion = $this->createUser('champion@example.org', displayName: 'Champion', slug: 'champion');
        $friend = $this->friend('friend');
        foreach ([[$champion, 3], [$friend, 2], [$this->viewer, 1]] as [$player, $goals]) {
            $registration = $this->createRegistration($event->getId(), $player->getId());
            for ($i = 0; $i < $goals; ++$i) {
                $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registration->getId(), $game->getId(), $player->getDisplayName().$i, $i);
                $slot->recordGoal($now->modify('+1 hour'));
                $this->entityManager->persist($slot);
            }
        }
        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/leaderboard?axis=goals');
        self::assertSame(['champion', 'friend', 'viewer'], $this->boardSlugs());

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/leaderboard?axis=goals&friendsOnly=1');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $data = $this->decodedJsonResponse();
        self::assertSame(['friend', 'viewer'], $this->boardSlugs($data));
        self::assertIsArray($data['data']);
        self::assertIsArray($data['data'][0]);
        self::assertSame(1, $data['data'][0]['rank'], 'ranks start again within the friends');
        self::assertIsArray($data['meta']);
        self::assertSame(2, $data['meta']['total']);
    }

    public function testAnAnonymousFriendsBoardIsEmpty(): void
    {
        $this->client->request('GET', '/api/v1/leaderboard?axis=speed&friendsOnly=1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->boardSlugs());
    }

    public function testTheWeeklyRunListsTheFriendsBestAttemptRankedByTime(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $runId = bin2hex(random_bytes(16));
        $fast = $this->friend('fast');
        $slow = $this->friend('slow');
        $launched = $this->friend('launched');
        $stranger = $this->createUser('stranger@example.org', slug: 'stranger');

        $this->entry($runId, $fast, 1, $now, 600);
        $this->entry($runId, $slow, 1, $now, 900);
        $this->entry($runId, $slow, 2, $now, 1200);
        $this->entry($runId, $launched, 1, $now, null);
        $this->entry($runId, $this->viewer, 1, null, null);
        $this->entry($runId, $stranger, 1, $now, 300);
        $this->entry(bin2hex(random_bytes(16)), $fast, 1, $now, 100);
        $this->entityManager->flush();

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/weekly-runs/'.$runId.'/friends');
        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);

        $standings = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $standings[] = [$row['slug'], $row['status'], $row['completionTimeSeconds'], $row['isViewer']];
        }
        self::assertSame([
            ['fast', 'goal', 600, false],
            ['slow', 'goal', 900, false],
            ['launched', 'launched', null, false],
            ['viewer', 'registered', null, true],
        ], $standings);

        $this->client->restart();
        $this->client->request('GET', '/api/v1/weekly-runs/'.$runId.'/friends');
        self::assertResponseStatusCodeSame(401);
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

    private function entry(string $runId, User $user, int $attempt, ?\DateTimeImmutable $launchedAt, ?int $seconds): void
    {
        $now = new \DateTimeImmutable('2026-05-01T09:00:00+00:00');
        $entry = new WeeklyEntry(bin2hex(random_bytes(16)), $runId, $user->getId(), $attempt, $now, $now);
        if (null !== $launchedAt) {
            $entry->launch(bin2hex(random_bytes(16)), $launchedAt, ['host' => 'bridge.local', 'port' => 38281, 'password' => null]);
        }
        if (null !== $launchedAt && null !== $seconds) {
            $entry->recordGoal($launchedAt->modify('+'.$seconds.' seconds'), $seconds, 40, 20);
        }
        $this->entityManager->persist($entry);
    }

    /**
     * @param array<mixed>|null $response
     *
     * @return list<string>
     */
    private function boardSlugs(?array $response = null): array
    {
        $rows = ($response ?? $this->decodedJsonResponse())['data'] ?? null;
        self::assertIsArray($rows);
        $slugs = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['slug']);
            $slugs[] = $row['slug'];
        }

        return $slugs;
    }
}
