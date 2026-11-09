<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Realtime\Infrastructure\Double\SpyHub;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Domain\Entity\PasseurOpening;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\ValueObject\PasseurItem;
use App\Sessions\Infrastructure\Double\StubPasseurBridge;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/** Story 52.4: a blocked player pays the Passeur to open a chest of an absent player's world. */
final class CallThePasseurTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret';

    private User $alice;
    private User $bob;
    private string $sessionId;
    private ?string $eventId = null;
    private ?string $runId = null;
    private StubPasseurBridge $bridge;
    private SpyHub $hub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        // The stubs below must outlive each request.
        $this->client->disableReboot();

        $bridge = static::getContainer()->get(StubPasseurBridge::class);
        self::assertInstanceOf(StubPasseurBridge::class, $bridge);
        $this->bridge = $bridge;
        $this->bridge->items = [
            new PasseurItem(2, 'Bob', 401, 'Link House', 101, 'Claw', true, 2),
            new PasseurItem(2, 'Bob', 403, 'Dam', 103, 'Geo', false, 1),
        ];
        $hub = static::getContainer()->get(SpyHub::class);
        self::assertInstanceOf(SpyHub::class, $hub);
        $this->hub = $hub;
        $this->hub->reset();
    }

    public function testAnEventCallIsPaidInSilverAndOpensTheChest(): void
    {
        $this->eventSession(['passeur' => true]);
        $this->silver($this->alice, 100);
        $this->gold($this->alice, 500);

        $this->loginAs($this->alice);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['kind' => 'event', 'price' => 60, 'share' => 30, 'itemName' => 'Claw', 'absentPlayerName' => 'Bob', 'replayed' => false], $this->decodedJsonResponse()['data']);
        self::assertSame(['2:401'], $this->bridge->opened);
        self::assertSame(40, $this->balance($this->alice, PelleKind::Event));
        self::assertSame(500, $this->balance($this->alice, PelleKind::Gold), 'gold is never spent on the Passeur');
        self::assertSame(30, $this->balance($this->bob, PelleKind::Event), 'the absent player gets their share');

        $opening = $this->onlyOpening();
        self::assertSame(PasseurOpening::STATUS_OPENED, $opening->getStatus());

        $feed = array_values(array_filter($this->hub->published, static fn ($u): bool => in_array('runs/'.$opening->getSessionId().'/feed', $u->getTopics(), true)));
        self::assertCount(1, $feed);
        self::assertStringContainsString('Le Passeur a ouvert un coffre du monde de Bob pour Alice', $feed[0]->getData());

        $notifications = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->bob->getId(), 'type' => 'passeur_opened']);
        self::assertCount(1, $notifications);
        self::assertSame('Claw', $notifications[0]->getPayload()['itemName'] ?? null);
    }

    public function testAPrivateRunCallIsPaidInBronze(): void
    {
        $this->runSession();
        $this->bronze($this->alice, 100);

        $this->loginAs($this->alice);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('bronze', $this->part('data')['kind'] ?? null);
        self::assertSame(40, $this->balance($this->alice, PelleKind::Bronze));
        self::assertSame(30, $this->balance($this->bob, PelleKind::Bronze));
    }

    public function testAChestTheBridgeCannotOpenIsRefunded(): void
    {
        $this->eventSession(['passeur' => true]);
        $this->silver($this->alice, 100);
        $this->bridge->openAnswer = PasseurBridgeInterface::ALREADY_OPENED;

        $this->loginAs($this->alice);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_already_opened', $this->errorCode());
        self::assertSame(100, $this->balance($this->alice, PelleKind::Event));
        self::assertSame(0, $this->balance($this->bob, PelleKind::Event));
        self::assertSame(PasseurOpening::STATUS_FAILED, $this->onlyOpening()->getStatus());

        // The same request again gives the same answer, and opens nothing more.
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['2:401'], $this->bridge->opened);
    }

    public function testARepeatedRequestIsPaidOnce(): void
    {
        $this->eventSession(['passeur' => true]);
        $this->silver($this->alice, 200);
        $this->loginAs($this->alice);

        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(200);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->part('data')['replayed'] ?? null);
        self::assertSame(140, $this->balance($this->alice, PelleKind::Event));
        self::assertSame(['2:401'], $this->bridge->opened);
    }

    public function testAChestAlreadyBeingOpenedIsNotSoldTwice(): void
    {
        $this->eventSession(['passeur' => true]);
        $this->silver($this->alice, 200);
        $this->loginAs($this->alice);

        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r2']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_busy', $this->errorCode());
        self::assertSame(140, $this->balance($this->alice, PelleKind::Event));
    }

    public function testEveryRefusalComesBeforeTheDebit(): void
    {
        $this->eventSession(['passeur' => true, 'passeurDailyLimit' => 1]);
        $this->silver($this->alice, 50);
        $this->loginAs($this->alice);

        // Not enough silver: the price is 60.
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('insufficient_pelles', $this->errorCode());

        // A price read before it moved.
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 54, 'requestId' => 'r2']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_price_changed', $this->errorCode());
        self::assertSame(['price' => 60], $this->part('error')['details'] ?? null);

        // Not progression, or not in an absent world: not a candidate.
        $this->call(['findingSlot' => 2, 'locationId' => 403, 'price' => 60, 'requestId' => 'r3']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_no_candidate', $this->errorCode());

        self::assertSame(50, $this->balance($this->alice, PelleKind::Event));
        self::assertSame([], $this->bridge->opened);

        // The day's limit of Bob's world.
        $this->silver($this->alice, 100);
        $this->bridge->items[] = new PasseurItem(2, 'Bob', 402, 'Pyramid', 102, 'Dash', true, 5);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r4']);
        self::assertResponseStatusCodeSame(200);
        $this->call(['findingSlot' => 2, 'locationId' => 402, 'price' => 60, 'requestId' => 'r5']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_daily_limit', $this->errorCode());
    }

    public function testThePasseurIsOffByDefaultAndOnlyForABlockedSlot(): void
    {
        $this->eventSession([], false);
        $this->silver($this->alice, 100);
        $this->loginAs($this->alice);

        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_disabled', $this->errorCode());

        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['enabled' => false, 'offer' => null], $this->decodedJsonResponse()['data']);

        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute($this->sessionId, ['passeur' => true]);
        $this->call(['findingSlot' => 2, 'locationId' => 401, 'price' => 60, 'requestId' => 'r2']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('passeur_not_blocked', $this->errorCode());
    }

    public function testThePlayerReadsTheOfferOfTheirSlotOnly(): void
    {
        $this->eventSession(['passeur' => true]);
        $this->silver($this->alice, 100);

        $this->loginAs($this->bob);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->alice);
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['enabled']);
        self::assertIsArray($data['offer']);
        self::assertSame(['Bob', 'Claw', 60, 'event', 100], [$data['offer']['absentPlayerName'], $data['offer']['itemName'], $data['offer']['price'], $data['offer']['kind'], $data['offer']['balance']]);
    }

    public function testTheBridgeAdminEchoIsKeptOutOfTheFeed(): void
    {
        $this->eventSession(['passeur' => true]);
        foreach (['Server: !admin /send_location Bob 401', 'Alice: !hint Claw'] as $text) {
            $this->client->jsonRequest('POST', sprintf('/api/v1/internal/sessions/%s/feed-push', $this->sessionId), [
                'type' => 'chat', 'text' => $text, 'timestamp' => '2026-11-09T10:00:00+00:00',
            ], ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
            self::assertResponseStatusCodeSame(200);
        }

        self::assertCount(1, $this->hub->published);
        self::assertStringContainsString('!hint Claw', $this->hub->published[0]->getData());
    }

    /**
     * @param array<string, mixed> $override
     */
    private function eventSession(array $override, bool $blocked = true): void
    {
        $now = new \DateTimeImmutable();
        $this->eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $aliceRegistration = $this->createRegistration($this->eventId, $this->alice->getId())->getId();
        $bobRegistration = $this->createRegistration($this->eventId, $this->bob->getId())->getId();
        $this->session($this->eventId, $aliceRegistration, $bobRegistration, $blocked);
        // Bob only comes tonight: absent now.
        $this->entityManager->persist(RegistrationPresenceWindow::create($bobRegistration, $now->modify('+6 hours'), $now->modify('+20 hours')));
        $this->entityManager->flush();
        if ([] !== $override) {
            $this->override($this->sessionId, $override);
        }
    }

    private function runSession(): void
    {
        $run = Run::create($this->alice->getId(), 'Run de test', new \DateTimeImmutable('-1 day'));
        $this->entityManager->persist($run);
        $this->runId = $run->getId();
        $this->session($run->getId(), $this->alice->getId(), $this->bob->getId(), true);
        $run->attachSession($this->sessionId);
        $this->entityManager->flush();
        // Bob was never seen in the 3 hours since the start: absent past the 2 hours of a private run.
        $this->override($run->getId(), ['passeur' => true, 'bronzePerCheck' => 0]);
    }

    private function session(string $ownerId, string $aliceSlotOwner, string $bobSlotOwner, bool $blocked): void
    {
        $this->sessionId = bin2hex(random_bytes(16));
        $started = new \DateTimeImmutable('-3 hours');
        $this->entityManager->persist(Session::createRunning($this->sessionId, $ownerId, 'localhost', 38281, null, null, $started));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $aliceSlotOwner, 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $bobSlotOwner, 'game-1', 'Bob', 2, 'slot-bob'));
        $this->entityManager->persist(new SessionPlayersSnapshot($this->sessionId, ['slots' => ['1' => ['slot_name' => 'Alice'], '2' => ['slot_name' => 'Bob']]], $started));
        if ($blocked) {
            $this->entityManager->persist(SlotBlockEpisode::open($this->sessionId, '1', 'Alice', new \DateTimeImmutable('-10 minutes')));
        }
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function override(string $scopeKey, array $values): void
    {
        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute($scopeKey, $values);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(array $payload): void
    {
        $this->client->jsonRequest('POST', $this->url(), $payload);
    }

    private function url(): string
    {
        return sprintf('/api/v1/sessions/%s/slots/1/passeur', $this->sessionId);
    }

    private function errorCode(): mixed
    {
        return $this->part('error')['code'] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function part(string $key): array
    {
        $part = $this->decodedJsonResponse()[$key] ?? null;
        self::assertIsArray($part);

        return $part;
    }

    private function silver(User $member, int $amount): void
    {
        $this->credit(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Event, $this->eventId, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    private function gold(User $member, int $amount): void
    {
        $this->credit(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    private function bronze(User $member, int $amount): void
    {
        $this->credit(new RecordPelleMovementInput($member->getId(), $amount, PelleKind::Bronze, null, PelleReason::BronzeStart, 'Départ', null, null, true, $this->runId));
    }

    private function credit(RecordPelleMovementInput $input): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record($input);
    }

    private function balance(User $member, PelleKind $kind): int
    {
        $this->entityManager->clear();
        $criteria = ['userId' => $member->getId(), 'kind' => $kind];
        if (PelleKind::Event === $kind) {
            $criteria['eventId'] = $this->eventId;
        }
        if (PelleKind::Bronze === $kind) {
            $criteria['runId'] = $this->runId;
        }

        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy($criteria),
        ));
    }

    private function onlyOpening(): PasseurOpening
    {
        $this->entityManager->clear();
        $openings = $this->entityManager->getRepository(PasseurOpening::class)->findAll();
        self::assertCount(1, $openings);

        return $openings[0];
    }
}
