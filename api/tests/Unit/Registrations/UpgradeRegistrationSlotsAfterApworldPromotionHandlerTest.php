<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Registrations\Application\Handler\UpgradeRegistrationSlotsAfterApworldPromotionHandler;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Tests\Unit\GameSelection\SpyNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Story 38.7: the slots of an event registration follow their game to its new apworld as long as the
 * event has not been generated.
 */
final class UpgradeRegistrationSlotsAfterApworldPromotionHandlerTest extends TestCase
{
    private const string OLD_DEFAULT = "name: Player{number}\ngame: Crystal Project\nCrystal Project:\n  goal:\n    astley: 50\n    true_astley: 0\n  removed_later:\n    'false': 50\n";
    private const string NEW_DEFAULT = "name: Player{number}\ngame: Crystal Project\nCrystal Project:\n  goal:\n    astley: 50\n    true_astley: 0\n    clamshells: 0\n  skip_quizard_quiz:\n    'false': 50\n";

    private Game $game;
    private Event $event;
    private SpyNotifier $notifier;
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->configureApworld('hash-new.apworld', 'hash-new', 'Crystal Project', self::NEW_DEFAULT, new \DateTimeImmutable());
        $this->game->recordOptionTypes(['goal' => ['type' => 'choice', 'values' => ['astley', 'true_astley', 'clamshells']]]);
        $now = new \DateTimeImmutable('2026-09-20');
        $this->event = Event::draft('LAN d\'automne', 'desc', new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable('2026-10-21'), 'Lille', 40, $now, new \DateTimeImmutable('2026-10-19'), true, $now);
        $this->notifier = new SpyNotifier();
    }

    public function testAnUntouchedYamlTakesTheNewDefault(): void
    {
        $registration = $this->registration(str_replace('Player{number}', 'Jean', self::OLD_DEFAULT));

        $this->handle([$registration]);

        $slot = $registration->getGameSlots()[0];
        self::assertSame(self::NEW_DEFAULT, $slot['playerYaml'] ?? null);
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertSame([], $this->notifier->sent);
        self::assertSame(1, $this->flushes);
    }

    public function testASlotWithoutYamlOnlyFollowsTheVersion(): void
    {
        $registration = $this->registration(null);

        $this->handle([$registration]);

        $slot = $registration->getGameSlots()[0];
        self::assertNull($slot['playerYaml'] ?? null, 'the default is resolved at generation, nothing to store');
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
    }

    public function testACustomisedYamlThatStillHoldsIsKept(): void
    {
        $custom = "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: true_astley\n";
        $registration = $this->registration($custom);

        $this->handle([$registration]);

        self::assertSame($custom, $registration->getGameSlots()[0]['playerYaml'] ?? null);
        self::assertArrayNotHasKey('needsReview', $registration->getGameSlots()[0]);
        self::assertSame([], $this->notifier->sent);
    }

    public function testAYamlThatNoLongerHoldsIsMarkedAndItsPlayerNotified(): void
    {
        $custom = "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: moon\n";
        $registration = $this->registration($custom);

        $this->handle([$registration]);

        $slot = $registration->getGameSlots()[0];
        self::assertSame($custom, $slot['playerYaml'] ?? null);
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertSame(['« goal » : la valeur « moon » n\'est plus acceptée.'], $slot['needsReview'] ?? null);
        self::assertCount(1, $this->notifier->sent);
        self::assertSame('user-1', $this->notifier->sent[0]['recipientId']);
        self::assertSame('slot_yaml_needs_review', $this->notifier->sent[0]['type']);
        self::assertSame([
            'eventId' => $this->event->getId(),
            'eventTitle' => 'LAN d\'automne',
            'registrationId' => 'reg-1',
            'gameId' => $this->game->getId(),
            'gameName' => 'Crystal Project',
            'slotId' => 'slot-1',
            'reasons' => $slot['needsReview'],
        ], $this->notifier->sent[0]['payload']);
    }

    public function testAnEventAlreadyGeneratedIsNeverTouched(): void
    {
        $registration = $this->registration("name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: moon\n");

        $this->handle([$registration], withSession: true);

        self::assertSame('hash-old', $registration->getGameSlots()[0]['apworldHash'] ?? null);
        self::assertSame([], $this->notifier->sent);
    }

    public function testSlotsOfAnotherGameOrAnotherVersionAreLeftAlone(): void
    {
        $now = new \DateTimeImmutable('2026-09-20');
        $registration = new Registration('reg-1', $this->event->getId(), 'user-1', Registration::STATUS_RESERVED, $now, $now, [
            ['slotId' => 'other-game', 'gameId' => 'game-other', 'slotOrder' => 1, 'apworldHash' => 'hash-old'],
            ['slotId' => 'third-version', 'gameId' => $this->game->getId(), 'slotOrder' => 2, 'apworldHash' => 'hash-older'],
        ]);

        $this->handle([$registration]);

        self::assertSame('hash-old', $registration->getGameSlots()[0]['apworldHash'] ?? null);
        self::assertSame('hash-older', $registration->getGameSlots()[1]['apworldHash'] ?? null);
        self::assertSame(0, $this->flushes);
    }

    private function registration(?string $yaml): Registration
    {
        $now = new \DateTimeImmutable('2026-09-20');

        return new Registration('reg-1', $this->event->getId(), 'user-1', Registration::STATUS_RESERVED, $now, $now, [
            ['slotId' => 'slot-1', 'gameId' => $this->game->getId(), 'slotOrder' => 1, 'apworldHash' => 'hash-old', 'playerYaml' => $yaml],
        ]);
    }

    /**
     * @param list<Registration> $registrations
     */
    private function handle(array $registrations, bool $withSession = false): void
    {
        $events = self::createStub(EventRepositoryInterface::class);
        $events->method('findByStatuses')->willReturn([$this->event]);

        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findByEventId')->willReturn($withSession ? [Session::create('session-1', $this->event->getId(), new \DateTimeImmutable())] : []);

        $repository = self::createStub(RegistrationRepositoryInterface::class);
        $repository->method('findBy')->willReturn($registrations);
        $repository->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);

        new UpgradeRegistrationSlotsAfterApworldPromotionHandler($events, $sessions, $repository, $games, $this->notifier, new MockClock('2026-09-26 04:20:00+00:00'), new NullLogger())(
            new ApworldPromoted($this->game->getId(), 'hash-old', 'hash-new', self::OLD_DEFAULT),
        );
    }
}
