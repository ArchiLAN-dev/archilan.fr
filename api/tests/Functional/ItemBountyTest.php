<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\Sessions\Application\Command\RefundEndedSessionBounties;
use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.4: bounties on items, paid in pelles.
 */
final class ItemBountyTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret';

    private User $alice;
    private User $bob;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        // Alice is an admin, so she plays any slot of the session without a registration of her own.
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->sessionId = $this->runSession(bounties: true);
        $this->gold($this->alice, 100);
    }

    public function testAPlayerPostsABountyAndThePelleAreHeld(): void
    {
        $this->loginAs($this->alice);

        $this->postBounty(['itemName' => 'Grappin', 'amount' => 50, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedJsonResponse();
        self::assertSame('Alice', $body['slotName'] ?? null);
        self::assertSame(45, $body['reward'] ?? null, '10 % commission');
        self::assertSame(50, $this->balance($this->alice));

        $this->client->request('GET', sprintf('/api/v1/sessions/%s/slots/1/bounties', $this->sessionId));
        $list = $this->decodedJsonResponse();
        self::assertTrue($list['enabled'] ?? null);
        $bounties = $list['bounties'] ?? null;
        self::assertIsArray($bounties);
        self::assertCount(1, $bounties);
    }

    public function testASecondBountyOnTheSameItemLandsOnTheFirst(): void
    {
        $this->loginAs($this->alice);

        $this->postBounty(['itemName' => 'Grappin', 'amount' => 50, 'requestId' => 'r1']);
        $this->postBounty(['itemName' => 'grappin', 'amount' => 50, 'requestId' => 'r2']);

        self::assertCount(1, $this->entityManager->getRepository(ItemBounty::class)->findAll());
        self::assertSame(50, $this->balance($this->alice));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function refusedBounties(): iterable
    {
        yield 'too small' => [['itemName' => 'Grappin', 'amount' => 9, 'requestId' => 'r'], 422];
        yield 'too big' => [['itemName' => 'Grappin', 'amount' => 1001, 'requestId' => 'r'], 422];
        yield 'no item' => [['itemName' => ' ', 'amount' => 20, 'requestId' => 'r'], 422];
        yield 'more than the balance' => [['itemName' => 'Grappin', 'amount' => 101, 'requestId' => 'r'], 422];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedBounties')]
    public function testAMalformedBountyIsRefused(array $payload, int $status): void
    {
        $this->loginAs($this->alice);

        $this->postBounty($payload);

        self::assertResponseStatusCodeSame($status);
        self::assertCount(0, $this->entityManager->getRepository(ItemBounty::class)->findAll());
        self::assertSame(100, $this->balance($this->alice));
    }

    public function testAPartyWithoutBountiesRefuses(): void
    {
        $this->sessionId = $this->runSession(bounties: false);
        $this->loginAs($this->alice);

        $this->postBounty(['itemName' => 'Grappin', 'amount' => 50, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSenderOfTheItemWinsTheBountyMinusTheCommission(): void
    {
        $this->bountyOn('Grappin', 50);

        $this->feedPush('Bob', 'Alice', 'Grappin');

        self::assertSame(45, $this->balance($this->bob));
        self::assertSame(50, $this->balance($this->alice), 'the escrow is spent, the commission destroyed');
        $bounty = $this->onlyBounty();
        self::assertSame(ItemBounty::STATUS_PAID, $bounty->getStatus());
        self::assertSame($this->bob->getId(), $bounty->getWinnerId());
        self::assertCount(1, $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->bob->getId()]));
    }

    public function testAnotherItemLeavesTheBountyOpen(): void
    {
        $this->bountyOn('Grappin', 50);

        $this->feedPush('Bob', 'Alice', 'Bombe');

        self::assertSame(ItemBounty::STATUS_OPEN, $this->onlyBounty()->getStatus());
    }

    /**
     * @return iterable<string, array{string, \Closure(SessionSlot): void}>
     */
    public static function itemsNobodyFound(): iterable
    {
        yield 'sent to oneself' => ['Alice', static function (): void {}];
        yield 'from a slot nobody owns' => ['Bridge', static function (): void {}];
        yield 'from a slot that released' => ['Bob', static function (SessionSlot $bob): void { $bob->markAsReleased(); }];
        yield 'from a slot past its goal' => ['Bob', static function (SessionSlot $bob): void { $bob->recordGoal(new \DateTimeImmutable('2026-09-01T12:00:00+00:00')); }];
    }

    /**
     * @param \Closure(SessionSlot): void $prepareBob
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('itemsNobodyFound')]
    public function testAnItemNobodyFoundRefundsThePoster(string $sender, \Closure $prepareBob): void
    {
        $bob = $this->entityManager->getRepository(SessionSlot::class)->findOneBy(['sessionId' => $this->sessionId, 'slotName' => 'Bob']);
        self::assertInstanceOf(SessionSlot::class, $bob);
        $prepareBob($bob);
        $this->entityManager->flush();
        $this->bountyOn('Grappin', 50);

        $this->feedPush($sender, 'Alice', 'Grappin');

        self::assertSame(ItemBounty::STATUS_REFUNDED, $this->onlyBounty()->getStatus());
        self::assertSame(100, $this->balance($this->alice));
        self::assertSame(0, $this->balance($this->bob));
    }

    public function testThePosterWithdrawsTheirBounty(): void
    {
        $bountyId = $this->bountyOn('Grappin', 50);

        $this->loginAs($this->bob);
        $this->client->request('DELETE', sprintf('/api/v1/sessions/%s/bounties/%s', $this->sessionId, $bountyId));
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->alice);
        $this->client->request('DELETE', sprintf('/api/v1/sessions/%s/bounties/%s', $this->sessionId, $bountyId));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(100, $this->balance($this->alice));
        self::assertSame(ItemBounty::STATUS_WITHDRAWN, $this->onlyBounty()->getStatus());
    }

    public function testTheEndOfTheSessionRefundsOpenBounties(): void
    {
        $this->bountyOn('Grappin', 50);
        $this->entityManager->getConnection()->executeStatement('UPDATE session SET status = :status WHERE id = :id', ['status' => Session::STATUS_FINISHED, 'id' => $this->sessionId]);
        $this->entityManager->clear();

        $refund = self::getContainer()->get(RefundEndedSessionBounties::class);
        self::assertInstanceOf(RefundEndedSessionBounties::class, $refund);

        self::assertSame(1, $refund->refundEnded());
        self::assertSame(0, $refund->refundEnded());
        self::assertSame(100, $this->balance($this->alice));
    }

    private function runSession(bool $bounties): string
    {
        $run = Run::create($this->alice->getId(), 'Run de test', new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $sessionId = 'session-'.bin2hex(random_bytes(4));
        $this->entityManager->persist(Session::create($sessionId, $run->getId(), new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        $run->attachSession($sessionId);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $this->alice->getId(), 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $this->bob->getId(), 'game-1', 'Bob', 2, 'slot-bob'));
        $this->entityManager->persist(new SessionPlayersSnapshot($sessionId, ['slots' => ['1' => ['slot_name' => 'Alice'], '2' => ['slot_name' => 'Bob']]], new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE session SET status = :status WHERE id = :id', ['status' => Session::STATUS_RUNNING, 'id' => $sessionId]);
        // The session entity is still in the identity map as a draft.
        $this->entityManager->clear();
        if ($bounties) {
            $set = self::getContainer()->get(SetSessionConfigOverride::class);
            self::assertInstanceOf(SetSessionConfigOverride::class, $set);
            $set->execute($run->getId(), ['pelleBounties' => true]);
        }

        return $sessionId;
    }

    private function bountyOn(string $item, int $amount): string
    {
        $this->loginAs($this->alice);
        $this->postBounty(['itemName' => $item, 'amount' => $amount, 'requestId' => 'r-'.$item]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->decodedJsonResponse()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postBounty(array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/sessions/%s/slots/1/bounties', $this->sessionId), $payload);
    }

    private function feedPush(string $sender, string $receiver, string $item): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/internal/sessions/%s/feed-push', $this->sessionId), [
            'type' => 'item_sent',
            'text' => sprintf('%s found %s for %s', $sender, $item, $receiver),
            'timestamp' => '2026-10-03T10:00:30+00:00',
            'item' => ['id' => 42, 'name' => $item, 'flags' => 1],
            'location' => ['id' => 10, 'name' => 'Chest'],
            'sender' => ['slot' => 2, 'name' => $sender, 'game' => 'ALTTP'],
            'receiver' => ['slot' => 1, 'name' => $receiver, 'game' => 'ALTTP'],
        ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
    }

    private function gold(User $member, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    private function balance(User $member): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $member->getId(), 'kind' => PelleKind::Gold]),
        ));
    }

    private function onlyBounty(): ItemBounty
    {
        $this->entityManager->clear();
        $bounties = $this->entityManager->getRepository(ItemBounty::class)->findAll();
        self::assertCount(1, $bounties);

        return $bounties[0];
    }
}
