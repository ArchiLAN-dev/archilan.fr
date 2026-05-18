<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminRegistrationDetailTest extends FunctionalTestCase
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
        $this->client->jsonRequest('GET', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(401);
    }

    public function testStandardUserGets403(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/event-id/registrations/reg-id');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/nonexistent', $event->getId()));
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

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $eventB->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testReturnsRegistrationDetail(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER'], 'Jean Marius');
        $event = $this->makeEvent();
        $registration = $this->makeRegistration($event->getId(), $participant->getId());
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame(Registration::STATUS_RESERVED, $data['status']);
        self::assertFalse($data['usedPrivateAccess']);
        self::assertIsString($data['createdAt']);
        self::assertNull($data['submittedAt']);

        $participant_ = $data['participant'];
        self::assertIsArray($participant_);
        self::assertSame('Jean Marius', $participant_['displayName']);
        self::assertSame('user@example.org', $participant_['email']);

        self::assertFalse($data['gameSelectionComplete']);
        self::assertSame([], $data['games']);
    }

    public function testMarksPrivateAccess(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent();
        $registration = $this->makeRegistration($event->getId(), $participant->getId());
        $this->createPrivateAccessLog($event->getId(), $participant->getId(), granted: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertTrue($data['usedPrivateAccess']);
    }

    public function testMissingSelectedGameIsReturnedWithWarning(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(gameSelectionConfig: [['gameId' => 'missing-game']]);
        $registration = $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, ['missing-game']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertFalse($data['gameSelectionComplete']);

        $games = $data['games'];
        self::assertIsArray($games);
        self::assertCount(1, $games);
        $gameDetail = $games[0];
        self::assertIsArray($gameDetail);
        self::assertSame('missing-game', $gameDetail['gameId']);
        self::assertSame('missing-game', $gameDetail['gameName']);
        self::assertFalse($gameDetail['isComplete']);
        self::assertSame(["Le jeu sélectionné n'existe plus dans la bibliothèque."], $gameDetail['warnings']);
        self::assertNull($gameDetail['playerYaml']);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function makeEvent(
        bool $gameSelectionEnabled = false,
        array $gameSelectionConfig = [],
    ): Event {
        return $this->createEvent(
            'Spring Sync 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
            gameSelectionEnabled: $gameSelectionEnabled,
            gameSelectionConfig: $gameSelectionConfig,
        );
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
}
