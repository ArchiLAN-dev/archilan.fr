<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;

/**
 * Story 43.12: a player of a personal run nudges a co-player who has not played for two days.
 */
final class RunNudgeTest extends FunctionalTestCase
{
    private User $owner;

    private User $bob;

    private Run $run;

    private int $slots = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob', 'bob');
        $this->run = $this->playingRun('-5 days');
    }

    public function testAPlayerIdleForTwoDaysIsNudgedOnceADayWhoeverSendsIt(): void
    {
        $this->slot($this->owner, '-1 hour');
        $this->slot($this->bob, '-49 hours');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol', 'carol');
        $this->slot($carol, '-2 hours');

        $this->loginAs($this->owner);
        $state = $this->state();
        self::assertFalse($state['muted']);
        $bob = $this->playerRow($state, $this->bob);
        self::assertTrue($bob['idle']);
        self::assertTrue($bob['canNudge']);
        self::assertFalse($this->playerRow($state, $carol)['idle'], 'played two hours ago');

        $this->nudge($this->bob);
        self::assertResponseStatusCodeSame(200);
        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->bob->getId(), 'type' => 'run_nudge']);
        self::assertCount(1, $notices);
        self::assertSame('Ma run', $notices[0]->getPayload()['runTitle'] ?? null);
        self::assertSame('Owner', $notices[0]->getPayload()['senderName'] ?? null);
        self::assertFalse($this->playerRow($this->state(), $this->bob)['canNudge']);

        $this->loginAs($carol);
        $this->nudge($this->bob);
        self::assertResponseStatusCodeSame(429, 'the cap is shared by every sender');
        $refused = $this->decodedJsonResponse();
        self::assertIsArray($refused['error']);
        self::assertSame('already_nudged', $refused['error']['code']);
        self::assertIsArray($refused['data']);
        self::assertIsString($refused['data']['lastNudgedAt']);
        self::assertSame(1, $refused['data']['hoursAgo']);
        self::assertSame(1, $this->playerRow($this->state(), $this->bob)['nudgedHoursAgo']);

        $this->nudge($this->owner);
        self::assertResponseStatusCodeSame(409, 'the owner played an hour ago');
    }

    public function testAPlayerWhoNeverPlayedIsIdleOnceTheRunIsTwoDaysOld(): void
    {
        $this->slot($this->owner, '-1 hour');
        $this->slot($this->bob, null);

        $this->loginAs($this->owner);
        $this->nudge($this->bob);
        self::assertResponseStatusCodeSame(200);

        $fresh = $this->createUser('fresh@example.org', ['ROLE_USER'], 'Fresh', 'fresh');
        $young = $this->playingRun('-1 day');
        $this->slot($this->owner, '-1 hour', $young);
        $this->slot($fresh, null, $young);
        $this->client->request('POST', '/api/v1/runs/'.$young->getId().'/nudges/'.$fresh->getId());
        self::assertResponseStatusCodeSame(409, 'the run started a day ago');
    }

    public function testThePlayerTurnsNudgesOffForThisRun(): void
    {
        $this->slot($this->owner, '-1 hour');
        $this->slot($this->bob, '-3 days');

        $this->loginAs($this->bob);
        $this->client->request('PUT', '/api/v1/runs/'.$this->run->getId().'/nudges/mute', content: '{"muted":true}');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->state()['muted']);

        $this->loginAs($this->owner);
        self::assertTrue($this->playerRow($this->state(), $this->bob)['muted']);
        $this->nudge($this->bob);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([], $this->entityManager->getRepository(Notification::class)->findBy(['type' => 'run_nudge']));
    }

    public function testNoNudgeOnAnEndedRunTowardsAFinishedPlayerOneselfOrAcrossABlock(): void
    {
        $this->slot($this->owner, '-3 days');
        $done = $this->slot($this->bob, '-3 days');
        $done->recordGoal(new \DateTimeImmutable('-3 days'));
        $dave = $this->createUser('dave@example.org', ['ROLE_USER'], 'Dave', 'dave');
        $this->slot($dave, '-3 days');
        $this->entityManager->persist(Block::create($dave->getId(), $this->owner->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->owner);
        $this->nudge($this->bob);
        self::assertResponseStatusCodeSame(409, 'every slot reached its goal');
        $this->nudge($this->owner);
        self::assertResponseStatusCodeSame(409, 'not oneself');
        $this->nudge($dave);
        self::assertResponseStatusCodeSame(409, 'a block either way');

        $stranger = $this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger', 'stranger');
        $this->loginAs($stranger);
        $this->nudge($dave);
        self::assertResponseStatusCodeSame(403);

        $run = $this->entityManager->find(Run::class, $this->run->getId());
        self::assertInstanceOf(Run::class, $run);
        new \ReflectionProperty(Run::class, 'status')->setValue($run, Run::STATUS_COMPLETED);
        $this->entityManager->flush();
        $this->loginAs($dave);
        $this->nudge($this->owner);
        self::assertResponseStatusCodeSame(409, 'a completed run is over');
        self::assertFalse($this->playerRow($this->state(), $this->owner)['idle']);
    }

    private function playingRun(string $startedAgo): Run
    {
        $run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable($startedAgo));
        $sessionId = bin2hex(random_bytes(16));
        $session = Session::create($sessionId, $run->getId(), new \DateTimeImmutable($startedAgo));
        new \ReflectionProperty(Session::class, 'startedAt')->setValue($session, new \DateTimeImmutable($startedAgo));
        $run->attachSession($sessionId);
        new \ReflectionProperty(Run::class, 'status')->setValue($run, Run::STATUS_IDLE);
        $this->entityManager->persist($run);
        $this->entityManager->persist($session);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $this->owner->getId(), new \DateTimeImmutable($startedAgo)));
        $this->entityManager->flush();

        return $run;
    }

    private function slot(User $player, ?string $lastCheckAgo, ?Run $run = null): SessionSlot
    {
        $run ??= $this->run;
        $sessionId = $run->getSessionId();
        self::assertIsString($sessionId);
        ++$this->slots;
        $slot = SessionSlot::create('row-'.$this->slots, $sessionId, $player->getId(), 'game-x', 'P'.$this->slots, $this->slots, 'slot-'.$this->slots);
        if (null !== $lastCheckAgo) {
            $slot->recordCheckActivity(new \DateTimeImmutable($lastCheckAgo));
        }
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return $slot;
    }

    private function nudge(User $recipient): void
    {
        $this->client->request('POST', '/api/v1/runs/'.$this->run->getId().'/nudges/'.$recipient->getId());
    }

    /**
     * @return array<mixed>
     */
    private function state(): array
    {
        $this->client->request('GET', '/api/v1/runs/'.$this->run->getId().'/nudges');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $state
     *
     * @return array<mixed>
     */
    private function playerRow(array $state, User $player): array
    {
        self::assertIsArray($state['players']);
        foreach ($state['players'] as $row) {
            if (is_array($row) && ($row['userId'] ?? null) === $player->getId()) {
                return $row;
            }
        }
        self::fail('no row for '.$player->getDisplayName());
    }
}
