<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Application\Command\ExpireEventPelles;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.2: event pelles handed out during an event, and what becomes of them when it ends.
 */
final class EventPellesTest extends FunctionalTestCase
{
    private User $admin;
    private Event $event;
    private User $alice;
    private User $bob;
    private User $dan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->event = $this->createEvent('ArchiLAN #3', new \DateTimeImmutable('2030-11-01T10:00:00+00:00'), new \DateTimeImmutable('2030-11-02T18:00:00+00:00'), published: true);
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $carol = $this->createUser('carol@example.org', ['ROLE_USER'], 'Carol');
        $this->dan = $this->createUser('dan@example.org', ['ROLE_USER'], 'Dan');
        $this->dan->ban('Triche', new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $this->createRegistration($this->event->getId(), $this->alice->getId());
        $this->createRegistration($this->event->getId(), $this->bob->getId());
        $this->createRegistration($this->event->getId(), $carol->getId(), Registration::STATUS_CANCELLED);
        $this->createRegistration($this->event->getId(), $this->dan->getId());
        $this->entityManager->flush();
    }

    public function testAnAdminHandsOutEventPellesToEveryActiveRegistrant(): void
    {
        $this->loginAs($this->admin);

        $this->distribute(['amount' => 15, 'label' => 'Happening du samedi', 'requestId' => 'req-1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['credited' => 2, 'skipped' => 1, 'alreadyCredited' => 0], $this->decodedJsonResponse(), 'the banned registrant is skipped, the cancelled one is not a recipient');
        $movements = $this->movements(PelleReason::EventDistribution);
        self::assertCount(2, $movements);
        foreach ($movements as $movement) {
            self::assertSame(15, $movement->getAmount());
            self::assertSame(PelleKind::Event, $movement->getKind());
            self::assertSame($this->event->getId(), $movement->getEventId());
            self::assertSame('Happening du samedi', $movement->getLabel());
            self::assertSame($this->admin->getId(), $movement->getAuthorId());
        }
        self::assertCount(2, $this->entityManager->getRepository(Notification::class)->findBy(['type' => Notification::TYPE_PELLES_ADJUSTED]));
    }

    public function testTheSameRequestSentTwiceCreditsOnce(): void
    {
        $this->loginAs($this->admin);

        $this->distribute(['amount' => 15, 'label' => 'Happening', 'requestId' => 'req-1']);
        $this->distribute(['amount' => 15, 'label' => 'Happening', 'requestId' => 'req-1']);

        self::assertSame(['credited' => 0, 'skipped' => 1, 'alreadyCredited' => 2], $this->decodedJsonResponse());
        self::assertCount(2, $this->movements(PelleReason::EventDistribution));
    }

    public function testASelectionOnlyCreditsTheChosenRegistrants(): void
    {
        $this->loginAs($this->admin);

        $this->distribute(['amount' => 5, 'label' => 'Défi', 'requestId' => 'req-2', 'userIds' => [$this->alice->getId()]]);

        self::assertSame(['credited' => 1, 'skipped' => 0, 'alreadyCredited' => 0], $this->decodedJsonResponse());
        $movements = $this->movements(PelleReason::EventDistribution);
        self::assertCount(1, $movements);
        self::assertSame($this->alice->getId(), $movements[0]->getUserId());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedDistributions(): iterable
    {
        yield 'zero' => [['amount' => 0, 'label' => 'x', 'requestId' => 'r']];
        yield 'too much' => [['amount' => 1001, 'label' => 'x', 'requestId' => 'r']];
        yield 'no label' => [['amount' => 5, 'label' => ' ', 'requestId' => 'r']];
        yield 'no request id' => [['amount' => 5, 'label' => 'x']];
        yield 'empty selection' => [['amount' => 5, 'label' => 'x', 'requestId' => 'r', 'userIds' => []]];
        yield 'not a registrant' => [['amount' => 5, 'label' => 'x', 'requestId' => 'r', 'userIds' => ['someone-else']]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedDistributions')]
    public function testAMalformedDistributionIsRefused(array $payload): void
    {
        $this->loginAs($this->admin);

        $this->distribute($payload);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->movements(PelleReason::EventDistribution));
    }

    public function testAnEndedEventReceivesNoMoreDistribution(): void
    {
        $this->endEvent();
        $this->loginAs($this->admin);

        $this->distribute(['amount' => 5, 'label' => 'Trop tard', 'requestId' => 'req-3']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyAnAdminDistributes(): void
    {
        $this->loginAs($this->alice);

        $this->distribute(['amount' => 5, 'label' => 'x', 'requestId' => 'req-4']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheEndOfTheEventConvertsATenthToGoldAndDestroysTheRest(): void
    {
        $this->eventPelles($this->alice, 25);
        $this->eventPelles($this->bob, 9);
        $this->eventPelles($this->dan, 10);
        $this->endEvent();

        $first = $this->expire()->expireEnded();
        $second = $this->expire()->expireEnded();

        self::assertSame(3, $first->members);
        self::assertSame(2, $first->converted, 'alice: 10 % of 25 rounded down; bob: 0; dan is banned');
        self::assertSame(44, $first->destroyed);
        self::assertSame(0, $second->members, 'nothing left to expire');

        self::assertSame(0, $this->balance($this->alice, PelleKind::Event));
        self::assertSame(2, $this->balance($this->alice, PelleKind::Gold));
        self::assertSame(0, $this->balance($this->bob, PelleKind::Gold), 'no zero-pelle conversion line');
        self::assertCount(0, $this->movements(PelleReason::EventConversion, $this->bob));
        self::assertSame(0, $this->balance($this->dan, PelleKind::Event));
        self::assertSame(0, $this->balance($this->dan, PelleKind::Gold));
        self::assertCount(3, $this->movements(PelleReason::EventExpired));
    }

    public function testAnEventStillRunningKeepsItsPelles(): void
    {
        $this->eventPelles($this->alice, 25);

        self::assertSame(0, $this->expire()->expireEnded()->members);
        self::assertSame(25, $this->balance($this->alice, PelleKind::Event));
    }

    public function testTheAdminPageListsRegistrantsWithTheirBalance(): void
    {
        $this->eventPelles($this->alice, 25);
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/events/%s/pelles', $this->event->getId()));

        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertSame('ArchiLAN #3', $body['eventTitle'] ?? null);
        self::assertFalse($body['ended'] ?? null);
        self::assertSame(25, $body['distributed'] ?? null);
        self::assertSame(25, $body['inCirculation'] ?? null);
        $participants = $body['participants'] ?? null;
        self::assertIsArray($participants);
        self::assertSame(
            [['userId' => $this->alice->getId(), 'displayName' => 'Alice', 'balance' => 25, 'banned' => false], ['userId' => $this->bob->getId(), 'displayName' => 'Bob', 'balance' => 0, 'banned' => false], ['userId' => $this->dan->getId(), 'displayName' => 'Dan', 'balance' => 0, 'banned' => true]],
            $participants,
            'active registrants only, by name',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function distribute(array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/pelles', $this->event->getId()), $payload);
    }

    private function eventPelles(User $member, int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Event, $this->event->getId(), PelleReason::EventDistribution, 'Happening', $this->admin->getId(), null, true));
    }

    private function endEvent(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE event SET starts_at = '2020-01-01T10:00:00+00:00', ends_at = '2020-01-02T18:00:00+00:00' WHERE id = :id",
            ['id' => $this->event->getId()],
        );
        // The event entity is still in the identity map with its old dates.
        $this->entityManager->clear();
    }

    private function expire(): ExpireEventPelles
    {
        $expire = self::getContainer()->get(ExpireEventPelles::class);
        self::assertInstanceOf(ExpireEventPelles::class, $expire);

        return $expire;
    }

    private function balance(User $member, PelleKind $kind): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $member->getId(), 'kind' => $kind]),
        ));
    }

    /**
     * @return list<PelleMovement>
     */
    private function movements(PelleReason $reason, ?User $member = null): array
    {
        $criteria = ['reason' => $reason];
        if (null !== $member) {
            $criteria['userId'] = $member->getId();
        }

        return $this->entityManager->getRepository(PelleMovement::class)->findBy($criteria);
    }
}
