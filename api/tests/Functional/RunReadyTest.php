<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Handler\NotifyRunAllReadyJobHandler;
use App\PersonalRuns\Application\Message\NotifyRunAllReadyJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 16.23: a player of a draft run says they are ready, the run shows who is and how many, the owner hears when
 * everyone is.
 */
final class RunReadyTest extends FunctionalTestCase
{
    private User $owner;

    private Run $run;

    private string $gameId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable());
        $this->entityManager->persist($this->run);
        $this->gameId = $this->createGame('Hollow Knight', 'hollow-knight')->getId();
        $this->entityManager->flush();
        $this->participant($this->owner, [$this->gameId]);
    }

    public function testAPlayerWithAGameSaysTheyAreReadyThenTakesItBack(): void
    {
        $bob = $this->member('bob', [$this->gameId]);
        $this->loginAs($bob);

        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->participantOf($bob)->isReady());

        $run = $this->readRun();
        self::assertSame(['ready' => 1, 'total' => 2], $run['readiness']);
        self::assertTrue($this->row($run, $bob)['ready']);
        self::assertFalse($this->row($run, $this->owner)['ready']);

        $this->client->jsonRequest('DELETE', $this->url());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->participantOf($bob)->isReady());
    }

    public function testAPlayerWithNothingToPlayCannotBeReadyAndIsNotCounted(): void
    {
        $watcher = $this->member('watcher');
        $this->loginAs($watcher);

        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseStatusCodeSame(422);

        $run = $this->readRun();
        self::assertSame(['ready' => 0, 'total' => 1], $run['readiness'], 'only the owner, who has a game');
        self::assertFalse($this->row($run, $watcher)['canBeReady']);
    }

    public function testACoPlayerWithoutAGameCanBeReadyUntilTheyLoseTheSlot(): void
    {
        $bob = $this->member('bob', [$this->gameId]);
        $carol = $this->member('carol');
        $bobSlot = $this->participantOf($bob)->getGameSlots()[0]['slotId'];
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $bobSlot, $carol->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($carol);
        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->participantOf($carol)->isReady());

        // Bob drops the game Carol was playing with him: she has nothing left to be ready for.
        $this->loginAs($bob);
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => []]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertFalse($this->participantOf($carol)->isReady());
    }

    public function testChangingOnesGamesDropsTheStatusReorderingDoesNot(): void
    {
        $celeste = $this->createGame('Celeste', 'celeste')->getId();
        $bob = $this->member('bob', [$this->gameId, $celeste]);
        $this->loginAs($bob);
        $this->client->disableReboot();

        $this->client->jsonRequest('PUT', $this->url());
        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => [$celeste, $this->gameId]]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->participantOf($bob)->isReady(), 'the same games in another order');

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/participants/me/games', ['gameIds' => [$celeste]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->participantOf($bob)->isReady());
    }

    public function testTheLastPlayerReadyTellsTheOwnerOnce(): void
    {
        $bob = $this->member('bob', [$this->gameId]);
        $this->participantOf($this->owner)->markReady(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->disableReboot();
        $this->loginAs($bob);

        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseIsSuccessful();
        $jobs = $this->sentJobs();
        self::assertCount(1, $jobs);

        // Saying it again changes nothing and queues nothing.
        $this->client->jsonRequest('PUT', $this->url());
        self::assertSame([], $this->sentJobs());

        $this->handler()($jobs[0]);
        $notices = $this->noticesOf($this->owner);
        self::assertCount(1, $notices);
        self::assertSame('Ma run', $notices[0]->getPayload()['runTitle'] ?? null);
        self::assertSame(2, $notices[0]->getPayload()['readyCount'] ?? null);
    }

    public function testTheOwnerCompletingTheCountIsNotToldAndNeitherIsAStaleJob(): void
    {
        $bob = $this->member('bob', [$this->gameId]);
        $this->participantOf($bob)->markReady(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->disableReboot();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->sentJobs());

        // Bob took it back before the job ran: nothing to say.
        $this->participantOf($bob)->clearReady();
        $this->entityManager->flush();
        $this->handler()(new NotifyRunAllReadyJob($this->run->getId(), $bob->getId()));
        self::assertSame([], $this->noticesOf($this->owner));
    }

    public function testALaunchedRunHasNoReadiness(): void
    {
        $bob = $this->member('bob', [$this->gameId]);
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, Run::STATUS_ACTIVE);
        $this->entityManager->flush();
        $this->loginAs($bob);

        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseStatusCodeSame(409);

        $run = $this->readRun();
        self::assertNull($run['readiness']);
        self::assertFalse($this->row($run, $bob)['canBeReady']);
    }

    public function testAStrangerCannotSetAStatus(): void
    {
        $this->loginAs($this->createUser('eve@example.org', ['ROLE_USER'], 'Eve', 'eve'));
        $this->client->jsonRequest('PUT', $this->url());
        self::assertResponseStatusCodeSame(404);
    }

    private function url(): string
    {
        return '/api/v1/runs/'.$this->run->getId().'/ready';
    }

    /**
     * @return array<string, mixed>
     */
    private function readRun(): array
    {
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$this->run->getId());
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['data'] ?? null);

        /** @var array<string, mixed> $run */
        $run = $body['data'];

        return $run;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function row(array $run, User $member): array
    {
        self::assertIsArray($run['participants'] ?? null);
        foreach ($run['participants'] as $row) {
            if (is_array($row) && ($row['userId'] ?? null) === $member->getId()) {
                return array_filter($row, is_string(...), \ARRAY_FILTER_USE_KEY);
            }
        }
        self::fail('participant not listed');
    }

    /**
     * @param list<string> $gameIds
     */
    private function member(string $slug, array $gameIds = []): User
    {
        $member = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $this->participant($member, $gameIds);

        return $member;
    }

    /**
     * @param list<string> $gameIds
     */
    private function participant(User $member, array $gameIds): void
    {
        $participant = RunParticipant::create($this->run->getId(), $member->getId(), new \DateTimeImmutable());
        $participant->replaceSlots(array_map(static fn (string $gameId): array => ['slotId' => bin2hex(random_bytes(8)), 'gameId' => $gameId], $gameIds));
        $this->entityManager->persist($participant);
        $this->entityManager->flush();
    }

    private function participantOf(User $member): RunParticipant
    {
        $participant = $this->entityManager->getRepository(RunParticipant::class)->findOneBy(['runId' => $this->run->getId(), 'userId' => $member->getId()]);
        self::assertInstanceOf(RunParticipant::class, $participant);
        $this->entityManager->refresh($participant);

        return $participant;
    }

    /**
     * @phpstan-impure
     *
     * @return list<Notification>
     */
    private function noticesOf(User $recipient): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $recipient->getId(), 'type' => NotifyRunAllReadyJobHandler::NOTIFICATION_TYPE]);
    }

    /**
     * @return list<NotifyRunAllReadyJob>
     */
    private function sentJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $jobs = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyRunAllReadyJob) {
                $jobs[] = $message;
            }
        }

        return $jobs;
    }

    private function handler(): NotifyRunAllReadyJobHandler
    {
        $handler = self::getContainer()->get(NotifyRunAllReadyJobHandler::class);
        self::assertInstanceOf(NotifyRunAllReadyJobHandler::class, $handler);

        return $handler;
    }
}
