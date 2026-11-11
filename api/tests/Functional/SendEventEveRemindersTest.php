<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\Email\EventEveReminderEmail;
use App\Communications\Application\Message\EventEveReminderMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Application\Handler\SendEventEveRemindersHandler;
use App\Registrations\Application\Message\SendEventEveRemindersMessage;
use App\Registrations\Application\Query\RegistrantChecklistQuery;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Story 53.8: the confirmed registrants get one mail the day before their event. */
final class SendEventEveRemindersTest extends FunctionalTestCase
{
    /** 10:00 in Paris. */
    private const string STARTS_AT = '2026-11-21T09:00:00+00:00';

    /** @var list<object> */
    private array $dispatched = [];

    public function testTheConfirmedRegistrantIsToldOnceAboutADayAhead(): void
    {
        [, , $confirmed] = $this->eventWithRegistrations();

        // 40 hours ahead: too early; 23 hours ahead: in the window; an hour later: already sent.
        $this->runPassAt('2026-11-19T17:00:00+00:00');
        self::assertSame([], $this->dispatched);
        $this->runPassAt('2026-11-20T10:00:00+00:00');
        $this->runPassAt('2026-11-20T11:00:00+00:00');

        self::assertCount(1, $this->dispatched);
        $mail = $this->dispatched[0];
        self::assertInstanceOf(EventEveReminderMessage::class, $mail);
        self::assertSame('confirmed@example.org', $mail->userEmail);
        self::assertSame('ArchiLAN 4', $mail->eventTitle);
        self::assertSame('21/11/2026 à 10h00', $mail->startsAt);
        // Nothing required left on an event without game selection nor payment.
        self::assertSame([], $mail->stepsLeft);
        self::assertStringEndsWith('/mon-event', $mail->hubUrl);
        self::assertFalse($mail->localBroadcast);
        self::assertNotNull($this->reload($confirmed)->getEveReminderSentAt());
    }

    public function testWithAMediaServerTheMailMentionsTheRegie(): void
    {
        [$event] = $this->eventWithRegistrations();
        $event->pointMediaServer('media.lan.archilan.fr', new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->runPassAt('2026-11-20T10:00:00+00:00');
        self::assertCount(1, $this->dispatched);
        $mail = $this->dispatched[0];
        self::assertInstanceOf(EventEveReminderMessage::class, $mail);
        self::assertTrue($mail->localBroadcast);

        // Story 47.7: the mail says where the settings are, never the key.
        $email = new EventEveReminderEmail('a@example.org', null, 'ArchiLAN 4', 'demain', '', [], 'https://archilan.test/mon-event', '', true);
        self::assertStringContainsString(EventEveReminderEmail::LOCAL_BROADCAST_LINE, $email->textBody());
        self::assertStringContainsString('régie', $email->htmlBody());
        self::assertStringNotContainsString(EventEveReminderEmail::LOCAL_BROADCAST_LINE, new EventEveReminderEmail('a@example.org', null, 'ArchiLAN 4', 'demain', '', [], 'https://archilan.test/mon-event')->textBody());
    }

    public function testTheRegistrantWhoRefusedTheBroadcastHearsNothingAboutTheRegie(): void
    {
        [$event, , $confirmed] = $this->eventWithRegistrations();
        $event->pointMediaServer('media.lan.archilan.fr', new \DateTimeImmutable());
        $confirmed->chooseBroadcastConsent(false, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->runPassAt('2026-11-20T10:00:00+00:00');
        self::assertCount(1, $this->dispatched);
        $mail = $this->dispatched[0];
        self::assertInstanceOf(EventEveReminderMessage::class, $mail);
        self::assertFalse($mail->localBroadcast);
    }

    public function testTheUnconfirmedAndTheCancelledGetNothing(): void
    {
        [$event, $pending] = $this->eventWithRegistrations();
        $gone = $this->createUser('gone@example.org');
        $cancelled = new Registration('reg-gone', $event->getId(), $gone->getId(), Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-19T08:00:00+00:00'), new \DateTimeImmutable('2026-10-19T08:00:00+00:00'));
        $cancelled->confirm(new \DateTimeImmutable('2026-10-20T08:00:00+00:00'));
        $cancelled->cancel(new \DateTimeImmutable('2026-10-21T08:00:00+00:00'));
        $this->entityManager->persist($cancelled);
        $this->entityManager->flush();

        $this->runPassAt('2026-11-20T10:00:00+00:00');

        $emails = array_map(static fn (object $m): string => $m instanceof EventEveReminderMessage ? $m->userEmail : '', $this->dispatched);
        self::assertSame(['confirmed@example.org'], $emails);
        self::assertNull($this->reload($pending)->getEveReminderSentAt());
    }

    public function testAnEventWithTheReminderOffSendsNothing(): void
    {
        [$event] = $this->eventWithRegistrations();
        $event->switchEveReminder(false, new \DateTimeImmutable('2026-11-01T08:00:00+00:00'));
        $this->entityManager->flush();

        $this->runPassAt('2026-11-20T10:00:00+00:00');

        self::assertSame([], $this->dispatched);
    }

    public function testADraftEventSendsNothing(): void
    {
        $this->eventWithRegistrations(Event::STATUS_DRAFT);

        $this->runPassAt('2026-11-20T10:00:00+00:00');

        self::assertSame([], $this->dispatched);
    }

    public function testTooCloseToTheStartNothingGoes(): void
    {
        $this->eventWithRegistrations();

        // 8 hours ahead: past the window, a registration confirmed this late gets no mail.
        $this->runPassAt('2026-11-21T01:00:00+00:00');

        self::assertSame([], $this->dispatched);
    }

    public function testTheAdminSwitchesTheReminderOffAndASaveWithoutItKeepsIt(): void
    {
        $event = $this->createEvent('Spring Sync 2027', new \DateTimeImmutable('2027-05-31T10:00:00+00:00'), new \DateTimeImmutable('2027-05-31T22:00:00+00:00'), published: true);
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $payload = [
            'title' => 'Spring Sync 2027',
            'description' => 'Une session Archipelago.',
            'startsAt' => '2027-05-31T10:00:00+00:00',
            'endsAt' => '2027-05-31T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 48,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ];

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), $payload);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->eveReminderEnabledInResponse());

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), [...$payload, 'eveReminderEnabled' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->eveReminderEnabledInResponse());

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $event->getId()), $payload);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->eveReminderEnabledInResponse());
    }

    private function eveReminderEnabledInResponse(): bool
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsBool($data['eveReminderEnabled']);

        return $data['eveReminderEnabled'];
    }

    /**
     * @return array{0: Event, 1: Registration, 2: Registration}
     */
    private function eventWithRegistrations(string $status = Event::STATUS_PUBLISHED): array
    {
        $created = new \DateTimeImmutable('2026-10-19T08:00:00+00:00');
        $event = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable(self::STARTS_AT),
            new \DateTimeImmutable('2026-11-22T17:00:00+00:00'),
            capacity: 48,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2026-10-18T22:01:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable('2026-11-14T22:59:00+00:00'),
        );
        if (Event::STATUS_DRAFT !== $status) {
            $this->transitionEventTo($event, $status, $created);
        }

        $pendingUser = $this->createUser('pending@example.org');
        $confirmedUser = $this->createUser('confirmed@example.org');
        $pending = new Registration('reg-pending', $event->getId(), $pendingUser->getId(), Registration::STATUS_RESERVED, $created, $created);
        $confirmed = new Registration('reg-confirmed', $event->getId(), $confirmedUser->getId(), Registration::STATUS_RESERVED, $created, $created);
        $confirmed->confirm($created);
        $this->entityManager->persist($pending);
        $this->entityManager->persist($confirmed);
        $this->entityManager->flush();

        return [$event, $pending, $confirmed];
    }

    private function runPassAt(string $now): void
    {
        $container = self::getContainer();
        $events = $container->get(EventRepositoryInterface::class);
        $registrations = $container->get(RegistrationRepositoryInterface::class);
        $users = $container->get(UserRepositoryInterface::class);
        $checklist = $container->get(RegistrantChecklistQuery::class);
        self::assertInstanceOf(EventRepositoryInterface::class, $events);
        self::assertInstanceOf(RegistrationRepositoryInterface::class, $registrations);
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        self::assertInstanceOf(RegistrantChecklistQuery::class, $checklist);

        $test = $this;
        $bus = new readonly class($test) implements MessageBusInterface {
            public function __construct(private SendEventEveRemindersTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->record($message);

                return new Envelope($message, $stamps);
            }
        };

        $handler = new SendEventEveRemindersHandler($events, $registrations, $users, $checklist, $bus, new NullLogger(), new MockClock(new \DateTimeImmutable($now)), 'https://archilan.test');
        $handler(new SendEventEveRemindersMessage());
    }

    public function record(object $message): void
    {
        $this->dispatched[] = $message;
    }

    private function reload(Registration $registration): Registration
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->getRepository(Registration::class)->find($registration->getId());
        self::assertInstanceOf(Registration::class, $fresh);

        return $fresh;
    }
}
