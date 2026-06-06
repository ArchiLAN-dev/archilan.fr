<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Membership\Domain\Membership;
use App\Payments\Domain\HelloAssoOrder;
use App\Registrations\Domain\Registration;

final class AdminDashboardStatsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(401);
    }

    public function testStandardUserGets403(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsValidStatsShape(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);

        $data = $response['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('publishedEvents', $data);
        self::assertArrayHasKey('totalActiveRegistrations', $data);
        self::assertArrayHasKey('gameCount', $data);
        self::assertArrayHasKey('userCount', $data);
        self::assertArrayHasKey('activeMemberCount', $data);
        self::assertArrayHasKey('totalRevenueCents', $data);
        self::assertIsInt($data['publishedEvents']);
        self::assertIsInt($data['totalActiveRegistrations']);
        self::assertIsInt($data['gameCount']);
        self::assertIsInt($data['userCount']);
        self::assertIsInt($data['activeMemberCount']);
        self::assertIsInt($data['totalRevenueCents']);
    }

    public function testCountsPublishedEventsExcludesDrafts(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->makeEvent(Event::STATUS_DRAFT);
        $this->makeEvent(Event::STATUS_PUBLISHED);
        $this->makeEvent(Event::STATUS_IN_PROGRESS);
        $this->makeEvent(Event::STATUS_COMPLETED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(3, $data['publishedEvents']);
    }

    public function testCountsActiveRegistrationsExcludesCancelled(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(Event::STATUS_PUBLISHED);
        $this->createRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->createRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['totalActiveRegistrations']);
    }

    public function testCountsGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createGame('Game A', 'game-a');
        $this->createGame('Game B', 'game-b');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(2, $data['gameCount']);
    }

    public function testCountsUsers(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createUser('user1@example.org', ['ROLE_USER']);
        $this->createUser('user2@example.org', ['ROLE_USER']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(3, $data['userCount']);
    }

    public function testCountsActiveMembershipsExcludesExpired(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $now = new \DateTimeImmutable();
        $activeMembership = Membership::create('user-1', $now, $now->modify('+12 months'), 'admin', null, null, $now);
        $expiredMembership = Membership::create('user-2', $now->modify('-13 months'), $now->modify('-1 month'), 'admin', null, null, $now->modify('-13 months'));
        $this->entityManager->persist($activeMembership);
        $this->entityManager->persist($expiredMembership);
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['activeMemberCount']);
    }

    public function testSumsRevenueCentsFromPaidOrders(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $now = new \DateTimeImmutable();
        $paid = HelloAssoOrder::fromHelloAsso(1001, 'Membership', 'adhesion-2026', 'Payment', 1500, 'a@b.com', 'A', 'B', $now, $now);
        $unpaid = HelloAssoOrder::fromHelloAsso(1002, 'Membership', 'adhesion-2026', 'Pending', 1500, null, null, null, null, $now);
        $this->entityManager->persist($paid);
        $this->entityManager->persist($unpaid);
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(1500, $data['totalRevenueCents']);
    }

    private function makeEvent(string $status): Event
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Test Event',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
        );
        if (Event::STATUS_DRAFT !== $status) {
            $event->transitionTo(Event::STATUS_PUBLISHED, $now);
        }
        if (Event::STATUS_IN_PROGRESS === $status || Event::STATUS_COMPLETED === $status) {
            $event->transitionTo(Event::STATUS_IN_PROGRESS, $now);
        }
        if (Event::STATUS_COMPLETED === $status) {
            $event->transitionTo(Event::STATUS_COMPLETED, $now);
        }
        $this->entityManager->flush();

        return $event;
    }
}
