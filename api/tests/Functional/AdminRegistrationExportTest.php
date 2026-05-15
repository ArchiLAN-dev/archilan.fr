<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminRegistrationExportTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(EventPrivateAccessLog::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousGets401(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations/export');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserGets403(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownEventReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/nonexistent/registrations/export');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportsReservedRegistrationsOnly(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER'], 'Alice');
        $userB = $this->createUser('b@example.org', ['ROLE_USER'], 'Bob');
        $event = $this->makeEvent('Spring Sync 2027');
        $this->makeRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->makeRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $payload = $this->decodedJsonResponse();
        self::assertSame($event->getId(), $payload['eventId']);
        self::assertSame('Spring Sync 2027', $payload['eventTitle']);
        self::assertFalse($payload['includeCancelled']);
        self::assertIsString($payload['exportedAt']);

        $registrations = $payload['registrations'];
        self::assertIsArray($registrations);
        self::assertCount(1, $registrations);
        $row = $registrations[0];
        self::assertIsArray($row);
        self::assertSame(Registration::STATUS_RESERVED, $row['status']);
        $participant = $row['participant'];
        self::assertIsArray($participant);
        self::assertSame('Alice', $participant['displayName']);
    }

    public function testIncludeCancelledWhenRequested(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $userA = $this->createUser('a@example.org', ['ROLE_USER']);
        $userB = $this->createUser('b@example.org', ['ROLE_USER']);
        $event = $this->makeEvent('Spring Sync 2027');
        $this->makeRegistration($event->getId(), $userA->getId(), Registration::STATUS_RESERVED);
        $this->makeRegistration($event->getId(), $userB->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export?include_cancelled=true', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $payload = $this->decodedJsonResponse();
        self::assertTrue($payload['includeCancelled']);

        $registrations = $payload['registrations'];
        self::assertIsArray($registrations);
        self::assertCount(2, $registrations);
    }

    public function testIncludesPlayerYamlInExport(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent('Spring Sync 2027', gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, [$game->getId()]);
        $slotId = $registration->getGameSlots()[0]['slotId'];
        $registration->setSlotPlayerYaml($slotId, "name: Alice\ngame: Zelda OoT", 'abc123', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $payload = $this->decodedJsonResponse();
        $registrations = $payload['registrations'];
        self::assertIsArray($registrations);
        self::assertCount(1, $registrations);

        $row = $registrations[0];
        self::assertIsArray($row);
        self::assertFalse($row['usedPrivateAccess']);
        self::assertNull($row['submittedAt']);

        $games = $row['games'];
        self::assertIsArray($games);
        self::assertCount(1, $games);
        $gameRow = $games[0];
        self::assertIsArray($gameRow);
        self::assertSame($game->getId(), $gameRow['gameId']);
        self::assertSame('Zelda OoT', $gameRow['gameName']);
        self::assertSame("name: Alice\ngame: Zelda OoT", $gameRow['playerYaml']);

        $slotRows = $payload['slots'];
        self::assertIsArray($slotRows);
        self::assertCount(1, $slotRows);
        $slotRow = $slotRows[0];
        self::assertIsArray($slotRow);
        self::assertSame($row['registrationId'], $slotRow['registrationId']);
        self::assertSame($gameRow['slotId'], $slotRow['slotId']);
        self::assertSame(1, $slotRow['slotOrder']);
        self::assertSame($game->getId(), $slotRow['gameId']);
        self::assertSame("name: Alice\ngame: Zelda OoT", $slotRow['playerYaml']);
    }

    public function testExportsOneRowPerSlotForDuplicateGameSelections(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $participant = $this->createUser('user@example.org', ['ROLE_USER'], 'Alice');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent('Spring Sync 2027', gameSelectionConfig: [['gameId' => $game->getId()]]);
        $registration = $this->makeRegistration($event->getId(), $participant->getId(), Registration::STATUS_RESERVED, [$game->getId(), $game->getId()]);
        $slots = $registration->getGameSlots();
        $registration->setSlotPlayerYaml($slots[0]['slotId'], 'name: Alice1', 'hash1', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $registration->setSlotPlayerYaml($slots[1]['slotId'], 'name: Alice2', 'hash2', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $payload = $this->decodedJsonResponse();
        $slotRows = $payload['slots'];
        self::assertIsArray($slotRows);
        self::assertCount(2, $slotRows);

        $firstSlot = $slotRows[0];
        $secondSlot = $slotRows[1];
        self::assertIsArray($firstSlot);
        self::assertIsArray($secondSlot);
        self::assertSame($registration->getId(), $firstSlot['registrationId']);
        self::assertSame($registration->getId(), $secondSlot['registrationId']);
        self::assertSame($slots[0]['slotId'], $firstSlot['slotId']);
        self::assertSame($slots[1]['slotId'], $secondSlot['slotId']);
        self::assertSame(1, $firstSlot['slotOrder']);
        self::assertSame(2, $secondSlot['slotOrder']);
        self::assertSame($game->getId(), $firstSlot['gameId']);
        self::assertSame($game->getId(), $secondSlot['gameId']);
        self::assertSame('name: Alice1', $firstSlot['playerYaml']);
        self::assertSame('name: Alice2', $secondSlot['playerYaml']);
    }

    public function testEmptyEventExportsEmptyList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent('Spring Sync 2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $payload = $this->decodedJsonResponse();
        self::assertSame([], $payload['registrations']);
    }

    public function testResponseHasAttachmentHeader(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent('Spring Sync 2027');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/export', $event->getId()));
        self::assertResponseStatusCodeSame(200);

        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('registrations-', $disposition);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function makeEvent(
        string $title = 'Spring Sync 2027',
        array $gameSelectionConfig = [],
    ): Event {
        return $this->createEvent(
            $title,
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 48,
            published: true,
            gameSelectionEnabled: [] !== $gameSelectionConfig,
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
}
