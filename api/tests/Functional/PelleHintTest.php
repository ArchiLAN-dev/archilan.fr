<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Infrastructure\Double\SpyPelleHintGateway;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.3: hints bought with pelles.
 */
final class PelleHintTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->spy()->hints = [];
        $this->spy()->failNext = false;
        $this->spy()->refuseNext = null;
    }

    public function testAPlayerBuysAnItemHintWithGoldPelles(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['paidWith' => 'gold', 'price' => 20, 'balanceAfter' => 30, 'alreadyBought' => false], $this->decodedJsonResponse());
        self::assertSame([$sessionId.'/1/item:Grappin'], $this->spy()->hints);
        $purchase = $this->lines(PelleReason::HintPurchase);
        self::assertCount(1, $purchase);
        self::assertSame(-20, $purchase[0]->getAmount());
        self::assertSame('Hint : Grappin', $purchase[0]->getLabel());
    }

    public function testALocationHintHasItsOwnPrice(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'location', 'locationId' => 1234, 'requestId' => 'r1']);

        self::assertSame(10, $this->decodedJsonResponse()['price'] ?? null);
        self::assertSame([$sessionId.'/1/location:1234'], $this->spy()->hints);
    }

    public function testADoubleSubmitPaysAndHintsOnce(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);
        // Same container for both requests, so the spy sees both.
        $this->client->disableReboot();

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);
        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertTrue($this->decodedJsonResponse()['alreadyBought'] ?? null);
        self::assertCount(1, $this->spy()->hints);
        self::assertCount(1, $this->lines(PelleReason::HintPurchase));
    }

    public function testAFailedHintIsRefunded(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->spy()->failNext = true;
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Inconnu', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(409);
        $refund = $this->lines(PelleReason::HintRefund);
        self::assertCount(1, $refund);
        self::assertSame(20, $refund[0]->getAmount());
        self::assertSame(50, $this->balance(PelleKind::Gold, null));
    }

    public function testAHintThatAlreadyExistsIsRefundedWithTheReason(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->spy()->refuseNext = \App\Sessions\Application\Exception\HintNotGivenException::ALREADY_HINTED;
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('already_hinted', $error['code'] ?? null);
        self::assertSame("Pas de hint : ce hint existe déjà. Tes pelles t'ont été rendues.", $error['message'] ?? null);
        self::assertSame(50, $this->balance(PelleKind::Gold, null));
    }

    public function testAPartyThatDoesNotSellHintsRefuses(): void
    {
        $sessionId = $this->runSession(enabled: false);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->spy()->hints);
    }

    public function testNotEnoughPellesIsRefusedWithTheBalances(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 5);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('insufficient_pelles', $error['code'] ?? null);
        self::assertSame(['price' => 20, 'event' => null, 'gold' => 5], $error['details'] ?? null);
    }

    public function testAnEventSessionSpendsTheEventPellesFirst(): void
    {
        $event = $this->createEvent('ArchiLAN #3', new \DateTimeImmutable('2030-11-01T10:00:00+00:00'), new \DateTimeImmutable('2030-11-02T18:00:00+00:00'), published: true);
        $sessionId = $this->eventSession($event->getId());
        $this->pelles(PelleKind::Event, $event->getId(), 25);
        $this->pelles(PelleKind::Gold, null, 30);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);
        self::assertSame('event', $this->decodedJsonResponse()['paidWith'] ?? null);

        // 5 event pelles left: not enough, gold pays.
        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Bombe', 'requestId' => 'r2']);
        self::assertSame('gold', $this->decodedJsonResponse()['paidWith'] ?? null);
        self::assertSame(5, $this->balance(PelleKind::Event, $event->getId()));
        self::assertSame(10, $this->balance(PelleKind::Gold, null));
    }

    public function testASessionThatIsNotRunningRefuses(): void
    {
        $sessionId = $this->runSession(enabled: true, status: Session::STATUS_FINISHED);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(409);
    }

    public function testOnlyThePlayerOfTheSlotBuys(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->loginAs($this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger'));

        $this->buy($sessionId, ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOfferShowsPricesAndBalances(): void
    {
        $sessionId = $this->runSession(enabled: true);
        $this->pelles(PelleKind::Gold, null, 50);
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/sessions/%s/slots/1/pelle-hints', $sessionId));

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'itemPrice' => 20, 'locationPrice' => 10, 'eventBalance' => null, 'goldBalance' => 50], $this->decodedJsonResponse());
    }

    private function runSession(bool $enabled, string $status = Session::STATUS_RUNNING): string
    {
        $run = Run::create($this->admin->getId(), 'Run de test', new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $sessionId = 'session-'.bin2hex(random_bytes(4));
        $this->entityManager->persist(Session::create($sessionId, $run->getId(), new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        $run->attachSession($sessionId);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE session SET status = :status WHERE id = :id', ['status' => $status, 'id' => $sessionId]);
        $this->entityManager->clear();
        if ($enabled) {
            $this->override($run->getId());
        }

        return $sessionId;
    }

    private function eventSession(string $eventId): string
    {
        $sessionId = 'session-'.bin2hex(random_bytes(4));
        $this->entityManager->persist(Session::create($sessionId, $eventId, new \DateTimeImmutable('2026-09-01T10:00:00+00:00')));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE session SET status = :status WHERE id = :id', ['status' => Session::STATUS_RUNNING, 'id' => $sessionId]);
        $this->entityManager->clear();
        $this->override($sessionId);

        return $sessionId;
    }

    private function override(string $scopeKey): void
    {
        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute($scopeKey, ['pelleHints' => true]);
    }

    private function pelles(PelleKind $kind, ?string $eventId, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($this->admin->getId(), $amount, $kind, $eventId, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buy(string $sessionId, array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/sessions/%s/slots/1/pelle-hints', $sessionId), $payload);
    }

    private function spy(): SpyPelleHintGateway
    {
        $spy = self::getContainer()->get(SpyPelleHintGateway::class);
        self::assertInstanceOf(SpyPelleHintGateway::class, $spy);

        return $spy;
    }

    /**
     * @return list<PelleMovement>
     */
    private function lines(PelleReason $reason): array
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findBy(['reason' => $reason]);
    }

    private function balance(PelleKind $kind, ?string $eventId): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $this->admin->getId(), 'kind' => $kind, 'eventId' => $eventId]),
        ));
    }
}
