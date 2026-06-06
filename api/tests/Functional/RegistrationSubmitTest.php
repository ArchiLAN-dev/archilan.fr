<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\RegistrationConfirmationMessage;
use App\Events\Domain\Event;
use App\Registrations\Domain\Registration;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RegistrationSubmitTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testAnonymousGets401OnPost(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/registrations/nonexistent/submit');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/registrations/nonexistent/submit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationOwnedByOtherUserReturns404(): void
    {
        $owner = $this->createUser('owner@example.org');
        $other = $this->createUser('other@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: false);
        $registration = $this->makeRegistration($event->getId(), $owner->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($other);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelledRegistrationReturns404(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: false);
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_CANCELLED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testGameSelectionEnabledWithNoGamesReturns422(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: true);
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('games_required', $error['code']);
    }

    public function testGameSelectionEnabledWithGamesConfirms(): void
    {
        $user = $this->createUser('user@example.org');
        $game = $this->createGame('Zelda OoT', 'zelda-oot');
        $event = $this->makeEvent(
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
        );
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, [$game->getId()]);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame($event->getTitle(), $data['eventTitle']);
        self::assertIsArray($data['selectedGameIds']);
        self::assertContains($game->getId(), $data['selectedGameIds']);

        $meta = $response['meta'];
        self::assertIsArray($meta);
        self::assertIsString($meta['message']);
    }

    public function testGameSelectionDisabledConfirmsWithoutGames(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: false);
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertSame($event->getTitle(), $data['eventTitle']);
        self::assertSame([], $data['selectedGameIds']);
    }

    public function testFirstSubmitSetsSubmittedAtAndDispatchesMessage(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: false);
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Registration::class, $registration->getId());
        self::assertInstanceOf(Registration::class, $refreshed);
        self::assertNotNull($refreshed->getSubmittedAt());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RegistrationConfirmationMessage::class, $message);
        self::assertSame($user->getEmail(), $message->userEmail);
        self::assertSame($event->getTitle(), $message->eventTitle);
    }

    public function testDuplicateSubmitDoesNotDispatchSecondMessage(): void
    {
        $user = $this->createUser('user@example.org');
        $event = $this->makeEvent(gameSelectionEnabled: false);
        $registration = $this->makeRegistration($event->getId(), $user->getId(), Registration::STATUS_RESERVED, []);

        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', sprintf('/api/v1/registrations/%s/submit', $registration->getId()));
        self::assertResponseStatusCodeSame(200);

        // The kernel resets the in-memory transport between requests.
        // After the second submit, 0 new messages means the email was not re-dispatched.
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
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
    private function makeRegistration(string $eventId, string $userId, string $status, array $selectedGameIds): Registration
    {
        return $this->createRegistration($eventId, $userId, $status, $selectedGameIds);
    }
}
