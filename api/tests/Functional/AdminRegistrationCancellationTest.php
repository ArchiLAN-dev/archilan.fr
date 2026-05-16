<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminRegistrationCancellationTest extends FunctionalTestCase
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
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('DELETE', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/nonexistent', $event->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationFromOtherEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $eventA = $this->makeEvent();
        $eventB = $this->makeEvent();
        $registration = $this->makeRegistration($eventA->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $eventB->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelsReservedRegistration(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->makeRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        self::assertSame('cancelled', $response['outcome']);

        $this->entityManager->refresh($registration);
        self::assertSame(Registration::STATUS_CANCELLED, $registration->getStatus());
    }

    public function testAlreadyCancelledReturns409(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(409);
    }

    public function testCanCancelEvenWhenEventIsInProgress(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(status: Event::STATUS_IN_PROGRESS);
        $registration = $this->makeRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->refresh($registration);
        self::assertSame(Registration::STATUS_CANCELLED, $registration->getStatus());
    }

    public function testAdminCanModifyRegistrationGameSelection(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $gameA = $this->createGame('Zelda OoT', 'zelda-oot');
        $gameB = $this->createGame('Celeste', 'celeste');
        $event = $this->makeEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [
                ['gameId' => $gameA->getId()],
                ['gameId' => $gameB->getId()],
            ],
            maxGamesPerRegistrant: 2,
        );
        $registration = $this->makeRegistration($event->getId(), $participant->getId(), selectedGameIds: [$gameA->getId()]);
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'PATCH',
            sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()),
            [
                'slots' => [
                    ['gameId' => $gameA->getId()],
                    ['gameId' => $gameB->getId()],
                ],
            ],
        );

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertSame('updated', $meta['outcome']);

        $this->entityManager->refresh($registration);
        $gameSlots = $registration->getGameSlots();
        self::assertSame([$gameA->getId(), $gameB->getId()], array_column($gameSlots, 'gameId'));
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function makeEvent(
        string $status = Event::STATUS_PUBLISHED,
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
        ?int $maxGamesPerRegistrant = null,
    ): Event {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $event = $this->createEvent(
            'Spring Sync 2027',
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
        if ($gameSelectionEnabled) {
            $event->configureGameSelection(true, $gameSelectionConfig, $now, $maxGamesPerRegistrant);
        }
        $this->entityManager->flush();

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
        $registration = $this->createRegistration($eventId, $userId, $status);
        if ([] !== $selectedGameIds) {
            $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
            $slots = array_map(
                static fn (string $gameId, int $idx): array => [
                    'slotId' => bin2hex(random_bytes(8)),
                    'gameId' => $gameId,
                ],
                $selectedGameIds,
                array_keys($selectedGameIds),
            );
            $registration->replaceSlots($slots, $now);
            $this->entityManager->flush();
        }

        return $registration;
    }
}
