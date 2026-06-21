<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;

/**
 * The slot-goal callback for an event / personal run must capture the goal stats onto the matching
 * session_slot at goal time (bug #6) - matched by slot name, mirroring the archival path.
 */
final class SlotGoalSessionSlotTest extends FunctionalTestCase
{
    private const SECRET = 'test-runner-secret';

    public function testGoalCallbackRecordsStatsOntoSessionSlot(): void
    {
        [$session, $slot] = $this->createSessionWithSlot('Alice');

        $this->postGoal($session->getId(), [
            'slotId' => 1,
            'slotName' => 'Alice',
            'checksTotal' => 42,
            'itemsTotal' => 18,
            'goalReachedAt' => '2026-05-01T10:30:00+00:00',
        ]);

        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(SessionSlot::class, $slot->getId());
        self::assertInstanceOf(SessionSlot::class, $refreshed);
        self::assertSame(42, $refreshed->getChecksDone());
        self::assertSame(18, $refreshed->getItemsReceived());
        self::assertNotNull($refreshed->getGoalReachedAt());
    }

    public function testGoalCallbackIsIdempotentAndDoesNotOverwrite(): void
    {
        [$session, $slot] = $this->createSessionWithSlot('Bob');

        $this->postGoal($session->getId(), [
            'slotName' => 'Bob',
            'checksTotal' => 10,
            'itemsTotal' => 5,
            'goalReachedAt' => '2026-05-01T10:30:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(200);

        // Second callback with different totals must be a no-op (goal already captured).
        $this->postGoal($session->getId(), [
            'slotName' => 'Bob',
            'checksTotal' => 999,
            'itemsTotal' => 999,
            'goalReachedAt' => '2026-05-01T11:00:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(SessionSlot::class, $slot->getId());
        self::assertInstanceOf(SessionSlot::class, $refreshed);
        self::assertSame(10, $refreshed->getChecksDone());
        self::assertSame(5, $refreshed->getItemsReceived());
    }

    public function testGoalCallbackUnknownSlotNameIsNoOp(): void
    {
        [$session, $slot] = $this->createSessionWithSlot('Carol');

        $this->postGoal($session->getId(), [
            'slotName' => 'Nobody',
            'checksTotal' => 42,
            'itemsTotal' => 18,
            'goalReachedAt' => '2026-05-01T10:30:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(SessionSlot::class, $slot->getId());
        self::assertInstanceOf(SessionSlot::class, $refreshed);
        self::assertSame(0, $refreshed->getChecksDone());
        self::assertNull($refreshed->getGoalReachedAt());
    }

    public function testGoalCallbackWithoutSlotNameLeavesSlotUntouched(): void
    {
        [$session, $slot] = $this->createSessionWithSlot('Dora');

        // Legacy bridge payload (no slotName): non-weekly session → nothing to match, slot untouched.
        $this->postGoal($session->getId(), [
            'slotId' => 1,
            'checksTotal' => 42,
            'itemsTotal' => 18,
            'goalReachedAt' => '2026-05-01T10:30:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(SessionSlot::class, $slot->getId());
        self::assertInstanceOf(SessionSlot::class, $refreshed);
        self::assertSame(0, $refreshed->getChecksDone());
        self::assertNull($refreshed->getGoalReachedAt());
    }

    /**
     * @return array{0: Session, 1: SessionSlot}
     */
    private function createSessionWithSlot(string $slotName): array
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $user = $this->createUser($slotName.'@example.org', ['ROLE_USER'], $slotName, strtolower($slotName));
        $event = $this->createEvent('LAN '.$slotName, $now, $now->modify('+1 day'));
        $game = $this->createGame('Game '.$slotName, 'game-'.strtolower($slotName));
        $reg = $this->createRegistration($event->getId(), $user->getId());

        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), $now);
        $this->entityManager->persist($session);

        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $reg->getId(), $game->getId(), $slotName, 0);
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return [$session, $slot];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postGoal(string $sessionId, array $body): void
    {
        $this->client->request(
            'POST',
            '/api/v1/internal/sessions/'.$sessionId.'/slot-goal',
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => self::SECRET, 'CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
