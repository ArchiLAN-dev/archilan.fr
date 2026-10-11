<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 43.14: a draft run opened to its owner's friends, who find it and join it themselves.
 */
final class RunOpenToFriendsTest extends FunctionalTestCase
{
    private User $owner;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable());
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
    }

    public function testFriendsFindTheOpenRunAndJoinIt(): void
    {
        $friend = $this->friendOfOwner('friend');
        $stranger = $this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger', 'stranger');

        self::assertSame([], $this->openRunsOf($friend), 'on invitation by default');

        $this->open(Run::OPEN_FRIENDS, null);
        self::assertResponseStatusCodeSame(200);
        $runs = $this->openRunsOf($friend);
        self::assertCount(1, $runs);
        $row = $runs[0];
        self::assertIsArray($row);
        self::assertSame('Ma run', $row['title']);
        self::assertSame(0, $row['joined']);
        $owner = $row['owner'];
        self::assertIsArray($owner);
        self::assertSame('owner', $owner['slug']);
        self::assertSame([], $this->openRunsOf($stranger));
        self::assertSame([], $this->openRunsOf($this->owner), 'not to its owner');

        $this->loginAs($stranger);
        $this->join();
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($friend);
        $this->join();
        self::assertResponseStatusCodeSame(200);
        self::assertInstanceOf(RunParticipant::class, $this->participant($friend));
        self::assertSame([], $this->openRunsOf($friend), 'joined: no longer offered');
        $this->join();
        self::assertResponseStatusCodeSame(200, 'joining again is harmless');

        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->owner->getId(), 'type' => 'run_joined']);
        self::assertCount(1, $notices);
        self::assertSame('Friend', $notices[0]->getPayload()['joinerName'] ?? null);
    }

    public function testTheRunLeavesTheListOnceFull(): void
    {
        $first = $this->friendOfOwner('first');
        $second = $this->friendOfOwner('second');
        // The owner's own participant row takes no seat.
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->open(Run::OPEN_FRIENDS, 1);
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($first);
        $this->join();
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], $this->openRunsOf($second));
        $this->loginAs($second);
        $this->join();
        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->participant($second));
    }

    public function testABlockEitherWayHidesTheRun(): void
    {
        $blocker = $this->friendOfOwner('blocker');
        $blocked = $this->friendOfOwner('blocked');
        $this->entityManager->persist(Block::create($blocker->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->persist(Block::create($this->owner->getId(), $blocked->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->open(Run::OPEN_FRIENDS, null);

        foreach ([$blocker, $blocked] as $member) {
            self::assertSame([], $this->openRunsOf($member));
            $this->loginAs($member);
            $this->join();
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOnlyTheOwnerOpensADraftAndClosingHidesIt(): void
    {
        $friend = $this->friendOfOwner('friend');

        $this->loginAs($friend);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', content: '{"openness":"friends"}');
        self::assertResponseStatusCodeSame(403);

        $this->open('everyone', null);
        self::assertResponseStatusCodeSame(422);
        $this->open(Run::OPEN_FRIENDS, 0);
        self::assertResponseStatusCodeSame(422);

        $this->open(Run::OPEN_FRIENDS, 3);
        self::assertCount(1, $this->openRunsOf($friend));
        $this->open(Run::OPEN_INVITE, null);
        self::assertSame([], $this->openRunsOf($friend), 'back on invitation');

        $this->open(Run::OPEN_FRIENDS, null);
        $run = $this->entityManager->getRepository(Run::class)->find($this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        $run->start(new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame([], $this->openRunsOf($friend), 'launched: no longer a lobby');
        $this->open(Run::OPEN_INVITE, null);
        self::assertResponseStatusCodeSame(409);
    }

    public function testJoiningAcceptsAPendingInvitation(): void
    {
        $friend = $this->friendOfOwner('friend');
        $this->entityManager->persist(RunInvitation::send($this->run->getId(), $friend->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->open(Run::OPEN_FRIENDS, null);

        $this->loginAs($friend);
        $this->join();
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $invitation = $this->entityManager->getRepository(RunInvitation::class)->findOneBy(['runId' => $this->run->getId(), 'inviteeId' => $friend->getId()]);
        self::assertInstanceOf(RunInvitation::class, $invitation);
        self::assertSame(RunInvitation::ACCEPTED, $invitation->getStatus());
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function friendOfOwner(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $friendship = Friendship::request($this->owner->getId(), $user->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    private function open(string $openness, ?int $seats): void
    {
        $this->loginAs($this->owner);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', content: json_encode(['openness' => $openness, 'seatsWanted' => $seats], \JSON_THROW_ON_ERROR));
    }

    private function join(): void
    {
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/join-open');
    }

    /** @return list<mixed> */
    private function openRunsOf(User $viewer): array
    {
        $this->loginAs($viewer);
        $this->client->request('GET', '/api/v1/account/friends-open-runs');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }

    private function participant(User $member): ?RunParticipant
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(RunParticipant::class)->findOneBy(['runId' => $this->run->getId(), 'userId' => $member->getId()]);
    }
}
