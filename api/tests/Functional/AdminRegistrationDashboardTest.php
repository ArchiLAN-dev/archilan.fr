<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminRegistrationDashboardTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(HelloAssoOrder::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEmptyEventReturnsEmptyList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(0, $data);
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame(0, $meta['total']);
    }

    public function testListsRegistrationsWithParticipantInfo(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER'], 'Jean Marius');
        $event = $this->makeEvent();
        $this->makeRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame(1, $meta['total']);

        $row = $data[0];
        self::assertIsArray($row);
        self::assertSame(Registration::STATUS_RESERVED, $row['status']);
        self::assertFalse($row['usedPrivateAccess']);
        self::assertIsString($row['createdAt']);
        self::assertNull($row['submittedAt']);

        $participant_ = $row['participant'];
        self::assertIsArray($participant_);
        self::assertSame($participant->getId(), $participant_['userId']);
        self::assertSame('Jean Marius', $participant_['displayName']);
        self::assertSame('user@example.org', $participant_['email']);

        self::assertSame([], $row['selectedGames']);
        self::assertFalse($row['gameSelectionComplete']);
        self::assertArrayHasKey('payment', $row);
        self::assertNull($row['payment']);
    }

    public function testListsPaymentSummaryWhenOrderMatchesParticipantEmail(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('paid@example.org', ['ROLE_USER'], 'Paid Player');
        $event = $this->makeEvent(helloassoFormSlug: 'archilan-spring-2027');
        $this->makeRegistration($event->getId(), $participant->getId());
        $this->createOrder('archilan-spring-2027', 'paid@example.org', 'processed', 2500, false);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertIsArray($row['payment']);
        self::assertSame('processed', $row['payment']['status']);
        self::assertSame(2500, $row['payment']['amountCents']);
        self::assertFalse($row['payment']['isStale']);
    }

    public function testMarksPrivateAccessRegistrations(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $this->makeRegistration($event->getId(), $participant->getId());
        $this->createPrivateAccessLog($event->getId(), $participant->getId(), granted: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);
        self::assertTrue($row['usedPrivateAccess']);
    }

    public function testFilterByStatusReserved(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $this->makeRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->makeRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations?status=reserved', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row0 = $data[0];
        self::assertIsArray($row0);
        self::assertSame(Registration::STATUS_RESERVED, $row0['status']);
    }

    public function testFilterByStatusCancelled(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $this->makeRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->makeRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations?status=cancelled', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $row0 = $data[0];
        self::assertIsArray($row0);
        self::assertSame(Registration::STATUS_CANCELLED, $row0['status']);
    }

    public function testIncludesSelectedGamesSummaryAndCompleteness(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent(gameSelectionConfig: [['gameId' => $game->getId()]]);
        $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, [$game->getId()]);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);

        $selectedGames = $row['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $game0 = $selectedGames[0];
        self::assertIsArray($game0);
        self::assertSame($game->getId(), $game0['gameId']);
        self::assertSame('Zelda OoT', $game0['gameName']);
        self::assertTrue($row['gameSelectionComplete']);
    }

    public function testMissingSelectedGameMarksSelectionIncomplete(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(gameSelectionConfig: [['gameId' => 'missing-game']]);
        $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, ['missing-game']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $row = $data[0];
        self::assertIsArray($row);

        $selectedGames = $row['selectedGames'];
        self::assertIsArray($selectedGames);
        self::assertCount(1, $selectedGames);
        $game0 = $selectedGames[0];
        self::assertIsArray($game0);
        self::assertSame('missing-game', $game0['gameId']);
        self::assertSame('missing-game', $game0['gameName']);
        self::assertFalse($row['gameSelectionComplete']);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function makeEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?string $helloassoFormSlug = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
            gameSelectionEnabled: $gameSelectionEnabled,
            gameSelectionConfig: $gameSelectionConfig,
        );
        if (null !== $helloassoFormSlug) {
            $event->setHelloassoFormSlug($helloassoFormSlug, $now);
            $this->entityManager->flush();
        }

        return $event;
    }

    /**
     * @param list<string> $selectedGameIds
     */
    private function makeRegistration(
        string $eventId,
        string $userId,
        string $status = Registration::STATUS_RESERVED,
        array $selectedGameIds = [],
    ): Registration {
        return $this->createRegistration($eventId, $userId, $status, $selectedGameIds);
    }

    private function createPrivateAccessLog(string $eventId, string $userId, bool $granted): EventPrivateAccessLog
    {
        $log = new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $eventId,
            $userId,
            $granted,
            new \DateTimeImmutable('2026-05-01T10:00:00+00:00'),
        );

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function createOrder(string $formSlug, string $payerEmail, string $status, int $amountCents, bool $stale): HelloAssoOrder
    {
        $now = $stale
            ? new \DateTimeImmutable('-48 hours')
            : new \DateTimeImmutable();

        $order = HelloAssoOrder::fromHelloAsso(
            random_int(100000, 999999),
            'evenements',
            $formSlug,
            $status,
            $amountCents,
            $payerEmail,
            'Jean',
            'Dupont',
            new \DateTimeImmutable('2026-04-20T14:00:00+00:00'),
            $now,
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
