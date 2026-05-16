<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminDashboardStatsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/dashboard-stats');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

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
        self::assertArrayHasKey('totalConfirmedRegistrations', $data);
        self::assertArrayHasKey('gameCount', $data);
        self::assertIsInt($data['publishedEvents']);
        self::assertIsInt($data['totalConfirmedRegistrations']);
        self::assertIsInt($data['gameCount']);
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

    public function testCountsReservedRegistrationsExcludesCancelled(): void
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
        self::assertSame(1, $data['totalConfirmedRegistrations']);
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
