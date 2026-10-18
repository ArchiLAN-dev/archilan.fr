<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Wallet\Application\Command\ReconcileRunBronze;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;

/**
 * Story 41.37: a private run's bronze, earned by playing it - a start for every player, then per check and at the
 * goal of a slot, for each of its players.
 */
final class RunBronzeEarningsTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret';

    private User $alice;
    private User $bob;
    private User $carol;
    private Run $run;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice', 'alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob', 'bob');
        // Carol co-plays Bob's slot.
        $this->carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol', 'carol');

        $this->run = Run::create($this->alice->getId(), 'Ma run', new \DateTimeImmutable('2026-10-18T10:00:00+00:00'));
        $this->entityManager->persist($this->run);
        $this->sessionId = 'session-'.bin2hex(random_bytes(4));
        $this->entityManager->persist(Session::create($this->sessionId, $this->run->getId(), new \DateTimeImmutable('2026-10-18T10:00:00+00:00')));
        $this->run->attachSession($this->sessionId);
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, Run::STATUS_ACTIVE);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $this->alice->getId(), 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $this->bob->getId(), 'game-1', 'Bob', 2, 'slot-bob'));
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-bob', $this->carol->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    public function testEveryPlayerGetsTheStartOnceEvenAfterARestart(): void
    {
        $this->reconcile();
        $this->reconcile();

        self::assertSame([20, 20, 20], [$this->balance($this->alice), $this->balance($this->bob), $this->balance($this->carol)]);
        self::assertCount(3, $this->movements(PelleReason::BronzeStart));
    }

    public function testACoPlayerAddedLaterGetsTheStartToo(): void
    {
        $this->reconcile();
        $dan = $this->createUser('dan@example.org', ['ROLE_USER'], 'Dan', 'dan');
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-alice', $dan->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->reconcile();

        self::assertSame(20, $this->balance($dan));
        self::assertSame(20, $this->balance($this->alice), 'the others are not paid twice');
    }

    public function testACheckPaysEveryPlayerOfTheSlotOncePerLocation(): void
    {
        $this->feedPush('Bob', 'Alice', 10);
        $this->feedPush('Bob', 'Alice', 10);
        $this->feedPush('Bob', 'Bob', 11);

        self::assertSame(2, $this->balance($this->bob), 'the same location twice pays once, an item found for oneself pays');
        self::assertSame(2, $this->balance($this->carol), 'the co-player earns on their own balance');
        self::assertSame(0, $this->balance($this->alice), 'receiving is not checking');
    }

    /**
     * @return iterable<string, array{string, string, \Closure(SessionSlot, SessionSlot): void}>
     */
    public static function checksNobodyPlayed(): iterable
    {
        yield 'the sender released' => ['Bob', 'Alice', static function (SessionSlot $bob): void { $bob->markAsReleased(); }];
        yield 'the receiver collected' => ['Bob', 'Alice', static function (SessionSlot $bob, SessionSlot $alice): void { $alice->markAsReleased(); }];
        yield 'the sender had its goal' => ['Bob', 'Alice', static function (SessionSlot $bob): void { $bob->recordGoal(new \DateTimeImmutable('-1 minute')); }];
        yield 'a slot nobody plays' => ['Bridge', 'Alice', static function (): void {}];
    }

    /**
     * @param \Closure(SessionSlot, SessionSlot): void $arrange
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('checksNobodyPlayed')]
    public function testAnItemNobodyFoundPaysNothing(string $sender, string $receiver, \Closure $arrange): void
    {
        $arrange($this->slot('Bob'), $this->slot('Alice'));
        $this->entityManager->flush();

        $this->feedPush($sender, $receiver, 10);

        self::assertSame([], $this->movements(PelleReason::BronzeCheck));
    }

    public function testTheGoalPaysEveryPlayerOfTheSlotOnce(): void
    {
        $this->goal('Bob');
        $this->goal('Bob');

        self::assertSame(25, $this->balance($this->bob));
        self::assertSame(25, $this->balance($this->carol));
        self::assertSame(0, $this->balance($this->alice));
    }

    public function testTheOwnerSetsTheAmountsAndZeroTurnsASourceOff(): void
    {
        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute($this->run->getId(), ['bronzeStart' => 0, 'bronzePerCheck' => 3, 'bronzePerGoal' => 0]);

        $this->reconcile();
        $this->feedPush('Bob', 'Alice', 10);
        $this->goal('Bob');

        self::assertSame(3, $this->balance($this->bob));
        self::assertSame([], $this->movements(PelleReason::BronzeStart), 'no line at 0');
        self::assertSame([], $this->movements(PelleReason::BronzeGoal));
    }

    public function testABannedPlayerEarnsNothingAndTheOthersStillDo(): void
    {
        $this->carol->ban('Triche', new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $this->feedPush('Bob', 'Alice', 10);

        self::assertSame(1, $this->balance($this->bob));
        self::assertSame(0, $this->balance($this->carol));
    }

    public function testAnEndedRunGrantsNoStart(): void
    {
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, Run::STATUS_COMPLETED);
        $this->entityManager->flush();

        $this->reconcile();

        self::assertSame([], $this->movements(PelleReason::BronzeStart));
    }

    private function feedPush(string $sender, string $receiver, int $location): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/internal/sessions/%s/feed-push', $this->sessionId), [
            'type' => 'item_sent',
            'text' => sprintf('%s found an item for %s', $sender, $receiver),
            'timestamp' => '2026-10-18T10:00:30+00:00',
            'item' => ['id' => 42, 'name' => 'Grappin', 'flags' => 1],
            'location' => ['id' => $location, 'name' => 'Chest'],
            'sender' => ['slot' => 2, 'name' => $sender, 'game' => 'ALTTP'],
            'receiver' => ['slot' => 1, 'name' => $receiver, 'game' => 'ALTTP'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
    }

    private function goal(string $slotName): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/internal/sessions/%s/slot-goal', $this->sessionId), [
            'slotName' => $slotName,
            'checksTotal' => 40,
            'itemsTotal' => 38,
            'goalReachedAt' => '2026-10-18T12:00:00+00:00',
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
    }

    private function slot(string $name): SessionSlot
    {
        $slot = $this->entityManager->getRepository(SessionSlot::class)->findOneBy(['sessionId' => $this->sessionId, 'slotName' => $name]);
        self::assertInstanceOf(SessionSlot::class, $slot);

        return $slot;
    }

    private function reconcile(): void
    {
        $reconcile = self::getContainer()->get(ReconcileRunBronze::class);
        self::assertInstanceOf(ReconcileRunBronze::class, $reconcile);
        $reconcile->reconcile($this->run->getId());
    }

    private function balance(User $member): int
    {
        $ledger = self::getContainer()->get(PelleMovementRepositoryInterface::class);
        self::assertInstanceOf(PelleMovementRepositoryInterface::class, $ledger);

        return $ledger->balance($member->getId(), PelleKind::Bronze, null, $this->run->getId());
    }

    /**
     * @return list<PelleMovement>
     */
    private function movements(PelleReason $reason): array
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findBy(['reason' => $reason]);
    }
}
