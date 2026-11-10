<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\ValueObject\PasseurItem;
use App\Sessions\Infrastructure\Double\StubPasseurBridge;

/** Story 52.3: the admin's view of the Passeur's candidates for a slot. */
final class PasseurCandidatesTest extends FunctionalTestCase
{
    private User $admin;
    private string $sessionId;
    private string $url;
    private StubPasseurBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $now = new \DateTimeImmutable();
        $eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $aliceRegistration = $this->createRegistration($eventId, $alice->getId())->getId();
        $bobRegistration = $this->createRegistration($eventId, $bob->getId())->getId();

        $this->sessionId = bin2hex(random_bytes(16));
        $this->entityManager->persist(Session::create($this->sessionId, $eventId, $now->modify('-1 day')));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $aliceRegistration, 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->sessionId, $bobRegistration, 'game-1', 'Bob', 1, 'slot-bob'));
        $this->entityManager->persist(new SessionPlayersSnapshot($this->sessionId, ['slots' => ['1' => ['slot_name' => 'Alice'], '2' => ['slot_name' => 'Bob']]], $now));
        // Bob only comes tonight: absent now.
        $this->entityManager->persist(RegistrationPresenceWindow::create($bobRegistration, $now->modify('+6 hours'), $now->modify('+20 hours')));
        $this->entityManager->flush();

        $this->url = '/api/v1/admin/sessions/'.$this->sessionId.'/slots/1/passeur-candidates';
        $bridge = static::getContainer()->get(StubPasseurBridge::class);
        self::assertInstanceOf(StubPasseurBridge::class, $bridge);
        $this->bridge = $bridge;
        $this->bridge->items = [
            new PasseurItem(2, 'Bob', 401, 'Link House', 101, 'Claw', true, 2),
            new PasseurItem(2, 'Bob', 402, 'Pyramid', 102, 'Dash', true, 5),
            new PasseurItem(2, 'Bob', 403, 'Dam', 103, 'Geo', false, 1),
        ];
    }

    public function testOnlyAnAdminReadsIt(): void
    {
        $this->client->request('GET', $this->url);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER'], 'Zoé'));
        $this->client->request('GET', $this->url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testASlotNotInBkHasNoCandidate(): void
    {
        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['blocked' => false, 'blockedSince' => null, 'absentSlots' => [], 'ranking' => 'sphere', 'candidates' => []], $this->decodedJsonResponse()['data']);
        self::assertSame([], $this->bridge->measured);
    }

    public function testABlockedSlotGetsTheProgressionOfAbsentWorldsBestFirst(): void
    {
        $this->blockAlice();
        $this->bridge->gains = ['2:401' => 1, '2:402' => 9];

        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['blocked']);
        self::assertSame([2], $data['absentSlots']);
        self::assertSame('exact', $data['ranking']);
        self::assertIsArray($data['candidates']);
        self::assertSame(['Dash', 'Claw'], array_column($data['candidates'], 'itemName'));
        self::assertSame([9, 1], array_column($data['candidates'], 'unlocks'));
        self::assertSame(['findingSlot' => 2, 'findingPlayerName' => 'Bob', 'locationId' => 402, 'locationName' => 'Pyramid', 'itemId' => 102, 'itemName' => 'Dash', 'sphere' => 5, 'unlocks' => 9], $data['candidates'][0]);
    }

    public function testAnUntrackedGameFallsBackOnTheSpheres(): void
    {
        $this->blockAlice();
        $this->bridge->gains = null;

        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('sphere', $data['ranking']);
        self::assertIsArray($data['candidates']);
        self::assertSame(['Claw', 'Dash'], array_column($data['candidates'], 'itemName'));
    }

    public function testABlockSuspendedByARestartOffersNoPasseur(): void
    {
        // Story 52.4: until the slot's next state confirms it, a block interrupted by a restart is not one.
        $episode = SlotBlockEpisode::open($this->sessionId, '1', 'Alice', new \DateTimeImmutable('-10 minutes'), 8);
        $episode->suspend(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->persist($episode);
        $this->entityManager->flush();

        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertFalse($data['blocked']);
        self::assertSame([], $this->bridge->measured);
    }

    public function testABridgeThatCannotAnswerIsA503AndAnUnknownSessionA404(): void
    {
        $this->blockAlice();
        $this->bridge->items = null;
        $this->loginAs($this->admin);

        $this->client->request('GET', $this->url);
        self::assertResponseStatusCodeSame(503);

        $this->client->request('GET', '/api/v1/admin/sessions/nope/slots/1/passeur-candidates');
        self::assertResponseStatusCodeSame(404);
    }

    private function blockAlice(): void
    {
        $this->entityManager->persist(SlotBlockEpisode::open($this->sessionId, '1', 'Alice', new \DateTimeImmutable('-10 minutes')));
        $this->entityManager->flush();
    }
}
