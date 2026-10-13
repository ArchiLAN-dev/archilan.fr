<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\Message\RegistrationConfirmationReminderMessage;
use App\Communications\Application\Message\RegistrationReleasedMessage;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Realtime\Application\Service\RealtimePublisher;
use App\Registrations\Application\Handler\ProcessUnconfirmedRegistrationsHandler;
use App\Registrations\Application\Message\ProcessUnconfirmedRegistrationsMessage;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Story 4.23: unconfirmed reservations are reminded once, then released when registrations close. */
final class ProcessUnconfirmedRegistrationsTest extends FunctionalTestCase
{
    private const string CLOSES_AT = '2026-10-31T22:59:00+00:00';

    /** @var list<object> */
    private array $dispatched = [];

    public function testNothingHappensBeforeTheReminderTime(): void
    {
        [$event, $pending] = $this->eventWithRegistrations();

        $this->runPassAt('2026-10-29T22:00:00+00:00');

        self::assertSame([], $this->dispatched);
        self::assertNull($this->reload($pending)->getReminderSentAt());
        self::assertSame(Event::STATUS_PUBLISHED, $event->getStatus());
    }

    public function testRemindsTheUnconfirmedOnceAndLeavesTheConfirmedAlone(): void
    {
        [, $pending, $confirmed] = $this->eventWithRegistrations();

        $this->runPassAt('2026-10-29T23:30:00+00:00');
        $this->runPassAt('2026-10-30T00:30:00+00:00');

        self::assertCount(1, $this->dispatched);
        $reminder = $this->dispatched[0];
        self::assertInstanceOf(RegistrationConfirmationReminderMessage::class, $reminder);
        self::assertSame('pending@example.org', $reminder->userEmail);
        self::assertSame('31/10/2026 à 23h59', $reminder->registrationClosesAt);
        self::assertStringEndsWith(sprintf('/inscription/%s/recap', $pending->getId()), $reminder->recapUrl);
        self::assertNotNull($this->reload($pending)->getReminderSentAt());
        self::assertNull($this->reload($confirmed)->getReminderSentAt());
    }

    public function testAReservationMadeAfterTheReminderTimeIsNotReminded(): void
    {
        [$event] = $this->eventWithRegistrations();
        $late = $this->createUser('late@example.org');
        $this->entityManager->persist(new Registration('reg-late', $event->getId(), $late->getId(), Registration::STATUS_RESERVED, new \DateTimeImmutable('2026-10-30T12:00:00+00:00'), new \DateTimeImmutable('2026-10-30T12:00:00+00:00')));
        $this->entityManager->flush();

        $this->runPassAt('2026-10-30T13:00:00+00:00');

        $emails = array_map(static fn (object $m): string => $m instanceof RegistrationConfirmationReminderMessage ? $m->userEmail : '', $this->dispatched);
        self::assertSame(['pending@example.org'], $emails);
    }

    public function testReleasesTheUnconfirmedWhenRegistrationsClose(): void
    {
        [, $pending, $confirmed] = $this->eventWithRegistrations();

        $this->runPassAt('2026-10-31T23:20:00+00:00');
        $this->runPassAt('2026-11-01T00:20:00+00:00');

        self::assertSame(Registration::STATUS_CANCELLED, $this->reload($pending)->getStatus());
        self::assertSame(Registration::STATUS_RESERVED, $this->reload($confirmed)->getStatus());
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(RegistrationReleasedMessage::class, $this->dispatched[0]);
        self::assertSame('pending@example.org', $this->dispatched[0]->userEmail);
    }

    /**
     * @return array{0: Event, 1: Registration, 2: Registration}
     */
    private function eventWithRegistrations(): array
    {
        $created = new \DateTimeImmutable('2026-10-19T08:00:00+00:00');
        $event = $this->createEvent(
            'ArchiLAN 4',
            new \DateTimeImmutable('2026-11-21T09:00:00+00:00'),
            new \DateTimeImmutable('2026-11-22T17:00:00+00:00'),
            capacity: 48,
            isPublic: false,
            registrationOpensAt: new \DateTimeImmutable('2026-10-18T22:01:00+00:00'),
            registrationClosesAt: new \DateTimeImmutable(self::CLOSES_AT),
        );
        $this->transitionEventTo($event, Event::STATUS_PUBLISHED, $created);

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
        $counter = $container->get(RegistrationCounter::class);
        $realtime = $container->get(RealtimePublisher::class);
        self::assertInstanceOf(EventRepositoryInterface::class, $events);
        self::assertInstanceOf(RegistrationRepositoryInterface::class, $registrations);
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        self::assertInstanceOf(RegistrationCounter::class, $counter);
        self::assertInstanceOf(RealtimePublisher::class, $realtime);

        $test = $this;
        $bus = new readonly class($test) implements MessageBusInterface {
            public function __construct(private ProcessUnconfirmedRegistrationsTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->record($message);

                return new Envelope($message, $stamps);
            }
        };

        $handler = new ProcessUnconfirmedRegistrationsHandler($events, $registrations, $users, $counter, $realtime, $bus, new NullLogger(), new MockClock(new \DateTimeImmutable($now)), 'https://archilan.test');
        $handler(new ProcessUnconfirmedRegistrationsMessage());
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
