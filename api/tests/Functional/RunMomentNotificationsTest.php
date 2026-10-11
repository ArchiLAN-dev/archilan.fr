<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Handler\NotifyRunGamesChosenJobHandler;
use App\PersonalRuns\Application\Handler\NotifyRunPlayersJobHandler;
use App\PersonalRuns\Application\Message\NotifyRunGamesChosenJob;
use App\PersonalRuns\Application\Message\NotifyRunPlayersJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 43.28: the moments of a personal run told to its players - an arrival by any road, the games a player settled
 * on, the start and the recap.
 */
final class RunMomentNotificationsTest extends FunctionalTestCase
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

    public function testAnArrivalByTheLinkTellsTheOwnerOnce(): void
    {
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob', 'bob');
        $this->loginAs($bob);

        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$this->run->getInviteToken());
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$this->run->getInviteToken());
        self::assertResponseIsSuccessful();

        $notices = $this->noticesOf($this->owner, 'run_joined');
        self::assertCount(1, $notices, 'following the link again is no new arrival');
        self::assertSame('Bob', $notices[0]->getPayload()['joinerName'] ?? null);
        self::assertSame($this->run->getId(), $notices[0]->getPayload()['runId'] ?? null);
    }

    public function testTheOwnerFollowingTheirOwnLinkIsNoArrival(): void
    {
        $this->loginAs($this->owner);
        $this->client->jsonRequest('GET', '/api/v1/runs/join/'.$this->run->getInviteToken());
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->noticesOf($this->owner, 'run_joined'));
    }

    public function testSavingGamesQueuesADelayedNoticeForTheOwnerOnly(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $bob = $this->participant('bob');
        // One kernel for the whole test; the async transport is emptied at each request, so each check reads what
        // the last request queued.
        $this->client->disableReboot();
        $this->loginAs($bob);

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => [$game->getId()]]);
        self::assertResponseIsSuccessful();

        $jobs = $this->sentJobs(NotifyRunGamesChosenJob::class);
        self::assertCount(1, $jobs);
        [$job, $delay] = $jobs[0];
        self::assertSame([$game->getId()], $job->gameIds);
        self::assertSame(NotifyRunGamesChosenJob::SETTLE_MS, $delay);

        // The same games again: nothing changed, nothing queued.
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => [$game->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sentJobs(NotifyRunGamesChosenJob::class));

        // The owner choosing their own games tells nobody.
        $this->loginAs($this->owner);
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => [$game->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sentJobs(NotifyRunGamesChosenJob::class));
    }

    public function testOnlyTheSettledChoiceIsTold(): void
    {
        $hk = $this->createGame('Hollow Knight', 'hollow-knight');
        $celeste = $this->createGame('Celeste', 'celeste');
        $bob = $this->participant('bob', [$hk->getId(), $celeste->getId()]);
        $handler = $this->gamesHandler();

        // A job from an earlier choice: the player has moved on, it stays quiet.
        $handler(new NotifyRunGamesChosenJob($this->run->getId(), $bob->getId(), [$hk->getId()]));
        self::assertSame([], $this->noticesOf($this->owner, 'run_games_chosen'));

        $current = [$hk->getId(), $celeste->getId()];
        sort($current);
        $handler(new NotifyRunGamesChosenJob($this->run->getId(), $bob->getId(), $current));

        $notices = $this->noticesOf($this->owner, 'run_games_chosen');
        self::assertCount(1, $notices);
        self::assertSame(['Hollow Knight', 'Celeste'], $notices[0]->getPayload()['games'] ?? null, 'in the player\'s order');
        self::assertSame('Bob', $notices[0]->getPayload()['playerName'] ?? null);
    }

    public function testNothingIsToldOnceTheRunIsLaunched(): void
    {
        $hk = $this->createGame('Hollow Knight', 'hollow-knight');
        $bob = $this->participant('bob', [$hk->getId()]);
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, Run::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->gamesHandler()(new NotifyRunGamesChosenJob($this->run->getId(), $bob->getId(), [$hk->getId()]));

        self::assertSame([], $this->noticesOf($this->owner, 'run_games_chosen'));
    }

    public function testEveryPlayerHearsOfTheStartAndOfTheRecapOnce(): void
    {
        $hk = $this->createGame('Hollow Knight', 'hollow-knight');
        $bob = $this->participant('bob', [$hk->getId()]);
        $ownerAsPlayer = RunParticipant::create($this->run->getId(), $this->owner->getId(), new \DateTimeImmutable());
        $this->entityManager->persist($ownerAsPlayer);
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol', 'carol');
        $bobSlot = $this->participantOf($bob)->getGameSlots()[0]['slotId'];
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $bobSlot, $carol->getId(), new \DateTimeImmutable()));
        new \ReflectionProperty(Run::class, 'sessionId')->setValue($this->run, 'sess-run');
        $this->entityManager->flush();

        $handler = $this->playersHandler();
        $handler(NotifyRunPlayersJob::started('sess-run'));
        $handler(NotifyRunPlayersJob::recapReady('sess-run'));
        $handler(NotifyRunPlayersJob::started('sess-of-an-event'));

        foreach ([$this->owner, $bob, $carol] as $player) {
            self::assertCount(1, $this->noticesOf($player, 'run_started'), $player->getEmail());
            self::assertCount(1, $this->noticesOf($player, 'run_recap_ready'), $player->getEmail());
        }
        self::assertSame(['runId' => $this->run->getId(), 'runTitle' => 'Ma run'], $this->noticesOf($bob, 'run_started')[0]->getPayload());
    }

    /**
     * @param list<string> $gameIds
     */
    private function participant(string $slug, array $gameIds = []): User
    {
        $member = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $participant = RunParticipant::create($this->run->getId(), $member->getId(), new \DateTimeImmutable());
        $order = 0;
        $participant->replaceSlots(array_map(static function (string $gameId) use (&$order): array {
            return ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $gameId, 'slotOrder' => $order++];
        }, $gameIds));
        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $member;
    }

    private function participantOf(User $member): RunParticipant
    {
        $participant = $this->entityManager->getRepository(RunParticipant::class)->findOneBy(['runId' => $this->run->getId(), 'userId' => $member->getId()]);
        self::assertInstanceOf(RunParticipant::class, $participant);

        return $participant;
    }

    /**
     * Reads the database: two calls apart may differ.
     *
     * @phpstan-impure
     *
     * @return list<Notification>
     */
    private function noticesOf(User $recipient, string $type): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $recipient->getId(), 'type' => $type]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<array{0: T, 1: int|null}> each job, with its delay
     */
    private function sentJobs(string $class): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $jobs = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof $class) {
                $jobs[] = [$message, $envelope->last(DelayStamp::class)?->getDelay()];
            }
        }

        return $jobs;
    }

    private function gamesHandler(): NotifyRunGamesChosenJobHandler
    {
        $handler = self::getContainer()->get(NotifyRunGamesChosenJobHandler::class);
        self::assertInstanceOf(NotifyRunGamesChosenJobHandler::class, $handler);

        return $handler;
    }

    private function playersHandler(): NotifyRunPlayersJobHandler
    {
        $handler = self::getContainer()->get(NotifyRunPlayersJobHandler::class);
        self::assertInstanceOf(NotifyRunPlayersJobHandler::class, $handler);

        return $handler;
    }
}
