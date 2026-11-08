<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;

/** Story 5.9: an admin saves a registration slot's YAML on the player's behalf. */
final class AdminSlotYamlModificationTest extends FunctionalTestCase
{
    public function testStandardUserGets403(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('PUT', '/api/v1/admin/events/e/registrations/r/slots/s/yaml', ['playerYaml' => 'name: Link']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminReadsSelectionOpenAfterRegistrationsClosed(): void
    {
        [$event, $registration, $slotId] = $this->scenario();
        $this->loginAsAdmin();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s/game-selection', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['registrationOpen']);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        $first = $slots[0];
        self::assertIsArray($first);
        self::assertSame($slotId, $first['slotId']);
        $games = $data['availableGames'];
        self::assertIsArray($games);
        self::assertCount(1, $games);
    }

    public function testSelectionOfAnotherEventIs404(): void
    {
        [, $registration] = $this->scenario();
        $other = $this->createEvent('Autre', new \DateTimeImmutable('2027-01-01T10:00:00+00:00'), new \DateTimeImmutable('2027-01-02T10:00:00+00:00'));
        $this->loginAsAdmin();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s/game-selection', $other->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testSelectionIsAdminOnly(): void
    {
        [$event, $registration] = $this->scenario();
        $this->loginAs($this->createUser('lambda@example.org'));

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/events/%s/registrations/%s/game-selection', $event->getId(), $registration->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSavesYamlAfterRegistrationsClosed(): void
    {
        [$event, $registration, $slotId] = $this->scenario();
        $this->loginAsAdmin();
        $yaml = "name: Zelda\ngame: Hollow Knight\n";

        $this->client->jsonRequest('PUT', $this->url($event, $registration, $slotId), ['playerYaml' => $yaml]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->refresh($registration);
        $slot = $registration->getSlot($slotId);
        self::assertNotNull($slot);
        self::assertSame($yaml, $slot['playerYaml'] ?? null);
        self::assertSame('test-hash', $slot['apworldHash'] ?? null);
    }

    public function testUnknownSlotReturns404(): void
    {
        [$event, $registration] = $this->scenario();
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($event, $registration, 'nope'), ['playerYaml' => 'name: Link']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationFromOtherEventReturns404(): void
    {
        [, $registration, $slotId] = $this->scenario();
        $other = $this->createEvent('Autre', new \DateTimeImmutable('2027-01-01T10:00:00+00:00'), new \DateTimeImmutable('2027-01-02T10:00:00+00:00'));
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($other, $registration, $slotId), ['playerYaml' => 'name: Link']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationReturns409(): void
    {
        [$event, $registration, $slotId] = $this->scenario(Registration::STATUS_CANCELLED);
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($event, $registration, $slotId), ['playerYaml' => 'name: Link']);
        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('inactive_registration', $error['code']);
    }

    public function testInvalidSlotNameReturns422(): void
    {
        [$event, $registration, $slotId] = $this->scenario();
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($event, $registration, $slotId), ['playerYaml' => "name: 'Link le héros!'\n"]);
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('name', $details);
    }

    public function testGameWithoutApworldReturns422(): void
    {
        [$event, $registration, $slotId] = $this->scenario(apworld: false);
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($event, $registration, $slotId), ['playerYaml' => 'name: Link']);
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('game', $details);
    }

    public function testMissingYamlReturns422(): void
    {
        [$event, $registration, $slotId] = $this->scenario();
        $this->loginAsAdmin();

        $this->client->jsonRequest('PUT', $this->url($event, $registration, $slotId), []);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * An event whose registrations closed long ago, and a player's registration with one slot.
     *
     * @return array{Event, Registration, string}
     */
    private function scenario(string $status = Registration::STATUS_RESERVED, bool $apworld = true): array
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        if ($apworld) {
            $game->configureApworld('test-key', 'test-hash', 'Hollow Knight', "name: PlayerName\ngame: Hollow Knight\n", new \DateTimeImmutable('2026-05-06T10:00:00+00:00'));
            $this->entityManager->flush();
        }
        $event = $this->createEvent(
            'Event clos',
            new \DateTimeImmutable('2020-02-01T10:00:00+00:00'),
            new \DateTimeImmutable('2020-02-02T10:00:00+00:00'),
            published: true,
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
            registrationOpensAt: new \DateTimeImmutable('2020-01-01T10:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2020-01-15T10:00:00+00:00'),
        );
        $player = $this->createUser('player@example.org');
        $registration = $this->createRegistration($event->getId(), $player->getId(), $status, [$game->getId()]);

        return [$event, $registration, $registration->getGameSlots()[0]['slotId']];
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }

    private function url(Event $event, Registration $registration, string $slotId): string
    {
        return sprintf('/api/v1/admin/events/%s/registrations/%s/slots/%s/yaml', $event->getId(), $registration->getId(), $slotId);
    }
}
