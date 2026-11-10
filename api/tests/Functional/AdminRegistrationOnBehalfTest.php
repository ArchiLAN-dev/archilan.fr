<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\Message\RegistrationConfirmationMessage;
use App\Events\Domain\Entity\Event;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 5.10: an admin registers, puts back and confirms a member's registration on their behalf. Member-side rules
 * become warnings to acknowledge; only what would leave the registration unusable blocks.
 */
final class AdminRegistrationOnBehalfTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    public function testStandardUserGets403(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $this->loginAs($member);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/any/confirm');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/any/reactivate');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreatesRegistrationWithoutWarningsWhenEverythingIsOpen(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('created', $data['outcome']);
        $registration = $this->registration($this->stringValue($data['registrationId']));
        self::assertTrue($registration->isReserved());
        self::assertSame($this->admin->getId(), $registration->getCreatedByAdminId());
        self::assertSame($member->getId(), $registration->getUserId());
    }

    public function testEachMemberRuleIsAWarningUntilAcknowledged(): void
    {
        $member = $this->createUser('member@example.org', emailVerified: false);
        $other = $this->createUser('other@example.org');
        $event = $this->createEvent(
            'Private Sync',
            new \DateTimeImmutable('2099-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2099-05-31T22:00:00+00:00'),
            capacity: 1,
            published: true,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2020-06-01T00:00:00+00:00'),
        );
        $this->createRegistration($event->getId(), $other->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            ['registration_closed', 'capacity_full', 'email_not_verified', 'private_event'],
            $this->warningCodes(),
        );
        self::assertNull($this->findRegistration($event, $member), 'nothing is written before the acknowledgement');

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId(), 'acknowledgeWarnings' => true]);

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($this->findRegistration($event, $member));
    }

    public function testDraftAndNotYetOpenEventsAreWarnings(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->createEvent(
            'Draft Sync',
            new \DateTimeImmutable('2099-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2099-05-31T22:00:00+00:00'),
            registrationOpensAt: new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2099-05-01T00:00:00+00:00'),
        );
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['event_not_published', 'registration_not_open_yet'], $this->warningCodes());
    }

    public function testInProgressEventIsAWarningButACompletedOneBlocks(): void
    {
        $member = $this->createUser('member@example.org');
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $inProgress = $this->openEvent();
        $this->transitionEventTo($inProgress, Event::STATUS_IN_PROGRESS, $now);
        $completed = $this->openEvent();
        $this->transitionEventTo($completed, Event::STATUS_COMPLETED, $now);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($inProgress), ['userId' => $member->getId()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['event_in_progress'], $this->warningCodes());

        $this->client->jsonRequest('POST', $this->createUrl($completed), ['userId' => $member->getId(), 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('event_completed', $this->errorCode());
        self::assertNull($this->findRegistration($completed, $member));
    }

    public function testUnknownDeletedOrBannedMemberBlocks(): void
    {
        $event = $this->openEvent();
        $banned = $this->createUser('banned@example.org');
        $banned->ban('test', new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => 'nobody', 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $banned->getId(), 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('user_banned', $this->errorCode());

        $this->client->jsonRequest('POST', $this->createUrl($event), []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAlreadyActiveRegistrationIsReturnedNotDuplicated(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $existing = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('already_registered', $data['outcome']);
        self::assertSame($existing->getId(), $data['registrationId']);
    }

    public function testReactivatesAReleasedRegistrationWithItsGames(): void
    {
        $member = $this->createUser('member@example.org');
        $game = $this->createGame('Celeste', 'celeste');
        $event = $this->createEvent(
            'Closed Sync',
            new \DateTimeImmutable('2099-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2099-05-31T22:00:00+00:00'),
            published: true,
            gameSelectionEnabled: true,
            gameSelectionConfig: [['gameId' => $game->getId()]],
            registrationOpensAt: new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2020-06-01T00:00:00+00:00'),
        );
        $registration = $this->createRegistration($event->getId(), $member->getId(), selectedGameIds: [$game->getId()]);
        $registration->cancel(new \DateTimeImmutable('2020-06-01T00:01:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $url = $this->createUrl($event).'/'.$registration->getId().'/reactivate';
        $this->client->jsonRequest('POST', $url);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['registration_closed'], $this->warningCodes());

        $this->client->jsonRequest('POST', $url, ['acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(200);

        $restored = $this->registration($registration->getId());
        self::assertTrue($restored->isReserved());
        self::assertSame([$game->getId()], $restored->getSelectedGameIds(), 'the games come back with it');

        $this->client->jsonRequest('POST', $url, ['acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_active', $this->errorCode());
    }

    public function testCreatingForACancelledRegistrationRestoresIt(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $registration = $this->createRegistration($event->getId(), $member->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('restored', $data['outcome']);
        self::assertSame($registration->getId(), $data['registrationId']);
        self::assertTrue($this->registration($registration->getId())->isReserved());
    }

    public function testConfirmsOnBehalfAndSendsTheEmail(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$registration->getId().'/confirm');

        self::assertResponseStatusCodeSame(200);
        $confirmed = $this->registration($registration->getId());
        self::assertNotNull($confirmed->getSubmittedAt());
        self::assertSame($this->admin->getId(), $confirmed->getConfirmedByAdminId());
        self::assertCount(1, $this->sentConfirmations());

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$registration->getId().'/confirm');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_confirmed', $this->errorCode());
    }

    public function testConfirmWithoutEmailSendsNothing(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$registration->getId().'/confirm', ['sendEmail' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->registration($registration->getId())->getSubmittedAt());
        self::assertCount(0, $this->sentConfirmations());
    }

    public function testSlotWithoutYamlIsAWarningAndNoGameBlocks(): void
    {
        $member = $this->createUser('member@example.org');
        $other = $this->createUser('other@example.org');
        $game = $this->createGame('Celeste', 'celeste');
        $event = $this->openEvent(gameSelectionConfig: [['gameId' => $game->getId()]]);
        $withGame = $this->createRegistration($event->getId(), $member->getId(), selectedGameIds: [$game->getId()]);
        $withoutGame = $this->createRegistration($event->getId(), $other->getId());
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$withGame->getId().'/confirm');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['incomplete_yaml'], $this->warningCodes());
        self::assertNull($this->registration($withGame->getId())->getSubmittedAt());

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$withGame->getId().'/confirm', ['acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$withoutGame->getId().'/confirm', ['acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('games_required', $this->errorCode());
    }

    public function testCancelledRegistrationCannotBeConfirmed(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $registration = $this->createRegistration($event->getId(), $member->getId(), Registration::STATUS_CANCELLED);
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$registration->getId().'/confirm');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('inactive_registration', $this->errorCode());
    }

    public function testGamesOverTheCapOrOutsideTheEventAreWarnings(): void
    {
        $member = $this->createUser('member@example.org');
        $inEvent = $this->createGame('Celeste', 'celeste');
        $outside = $this->createGame('Hollow Knight', 'hollow-knight');
        $event = $this->openEvent(gameSelectionConfig: [['gameId' => $inEvent->getId()]], maxGames: 1);
        $registration = $this->createRegistration($event->getId(), $member->getId());
        $this->loginAs($this->admin);

        $url = $this->createUrl($event).'/'.$registration->getId();
        $slots = [['gameId' => $inEvent->getId()], ['gameId' => $outside->getId()]];

        $this->client->jsonRequest('PATCH', $url, ['slots' => $slots]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['max_games_exceeded', 'game_not_in_event'], $this->warningCodes());
        self::assertSame([], $this->registration($registration->getId())->getSelectedGameIds());

        $this->client->jsonRequest('PATCH', $url, ['slots' => $slots, 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$inEvent->getId(), $outside->getId()], $this->registration($registration->getId())->getSelectedGameIds());

        $this->client->jsonRequest('PATCH', $url, ['slots' => [['gameId' => 'missing']], 'acknowledgeWarnings' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDetailSaysWhenAnAdminCreatedAndConfirmed(): void
    {
        $member = $this->createUser('member@example.org');
        $event = $this->openEvent();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->createUrl($event), ['userId' => $member->getId()]);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $registrationId = $this->stringValue($data['registrationId']);
        $this->client->jsonRequest('POST', $this->createUrl($event).'/'.$registrationId.'/confirm', ['sendEmail' => false]);

        $this->client->jsonRequest('GET', $this->createUrl($event).'/'.$registrationId);

        self::assertResponseStatusCodeSame(200);
        $detail = $this->decodedJsonResponse()['data'];
        self::assertIsArray($detail);
        self::assertTrue($detail['createdByAdmin']);
        self::assertTrue($detail['confirmedByAdmin']);
    }

    /**
     * @param list<array{gameId: string}> $gameSelectionConfig
     */
    private function openEvent(array $gameSelectionConfig = [], ?int $maxGames = null): Event
    {
        return $this->createEvent(
            'Open Sync',
            new \DateTimeImmutable('2099-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2099-05-31T22:00:00+00:00'),
            published: true,
            gameSelectionEnabled: [] !== $gameSelectionConfig,
            gameSelectionConfig: $gameSelectionConfig,
            registrationOpensAt: new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2099-05-01T00:00:00+00:00'),
            gameSelectionMaxPerRegistrant: $maxGames,
        );
    }

    private function createUrl(Event $event): string
    {
        return sprintf('/api/v1/admin/events/%s/registrations', $event->getId());
    }

    /** @return list<string> */
    private function warningCodes(): array
    {
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);
        self::assertSame('warnings_to_acknowledge', $error['code']);
        $details = $error['details'];
        self::assertIsArray($details);
        $warnings = $details['warnings'];
        self::assertIsArray($warnings);

        $codes = [];
        foreach ($warnings as $warning) {
            self::assertIsArray($warning);
            self::assertIsString($warning['message']);
            $codes[] = $this->stringValue($warning['code']);
        }

        return $codes;
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'];
        self::assertIsArray($error);

        return $this->stringValue($error['code']);
    }

    private function stringValue(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function registration(string $id): Registration
    {
        $this->entityManager->clear();
        $registration = $this->entityManager->find(Registration::class, $id);
        self::assertInstanceOf(Registration::class, $registration);

        return $registration;
    }

    private function findRegistration(Event $event, User $member): ?Registration
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Registration::class)->findOneBy(['eventId' => $event->getId(), 'userId' => $member->getId()]);
    }

    /** @return list<RegistrationConfirmationMessage> */
    private function sentConfirmations(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RegistrationConfirmationMessage) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
