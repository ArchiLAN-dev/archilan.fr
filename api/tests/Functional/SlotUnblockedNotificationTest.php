<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Application\Message\NotifySlotUnblockedJob;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 40.1, end to end through the bridge's players push: a private run's slot that leaves a
 * real BK queues one notification job; an event session keeps no BK state at all.
 */
final class SlotUnblockedNotificationTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testLeavingARealBlockQueuesOneNotification(): void
    {
        $run = Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
        $run->attachSession('session-bk-1');
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        // One kernel for every push, so the test reads the transport the pushes wrote to.
        $this->client->disableReboot();

        $this->push('session-bk-1', 0);
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(SlotBlockEpisode::class)->findBy(['sessionId' => 'session-bk-1']));

        // The block started long enough ago to be a real one.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE session_slot_block SET blocked_since = NOW() - INTERVAL '5 minutes' WHERE session_id = 'session-bk-1'",
        );
        $this->entityManager->clear();

        $this->push('session-bk-1', 3);
        self::assertEquals([new NotifySlotUnblockedJob('session-bk-1', 'Alice_HK1', 3)], $this->queuedUnblockJobs());

        // The client resets the transport between requests: what the duplicate queues is all that shows.
        $this->push('session-bk-1', 3);
        self::assertSame([], $this->queuedUnblockJobs(), 'a duplicate push does not notify twice');

        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(SlotBlockEpisode::class)->findBy(['sessionId' => 'session-bk-1']));
    }

    public function testAnEventSessionKeepsNoBlockState(): void
    {
        $this->push('session-event-1', 0);

        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(SlotBlockEpisode::class)->findBy(['sessionId' => 'session-event-1']));
    }

    private function push(string $sessionId, int $reachableNow): void
    {
        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/players-push', $sessionId),
            ['slots' => ['1' => [
                'slot_name' => 'Alice_HK1',
                'checks_done' => 12,
                'checks_total' => 47,
                'items_received' => 8,
                'client_status' => 20,
                'goal_reached_at' => null,
                'reachable_now' => $reachableNow,
            ]]],
            ['HTTP_X_INTERNAL_SECRET' => self::SECRET],
        );
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return list<object>
     */
    private function queuedUnblockJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof NotifySlotUnblockedJob,
        ));
    }
}
