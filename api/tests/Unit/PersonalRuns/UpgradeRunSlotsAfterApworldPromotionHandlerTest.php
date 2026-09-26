<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\PersonalRuns\Application\Handler\UpgradeRunSlotsAfterApworldPromotionHandler;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Tests\Unit\GameSelection\SpyNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 38.7: the slots of personal runs not yet launched follow their game to its new apworld.
 */
final class UpgradeRunSlotsAfterApworldPromotionHandlerTest extends TestCase
{
    private const string OLD_DEFAULT = "name: Player{number}\ngame: Crystal Project\nCrystal Project:\n  goal:\n    astley: 50\n    true_astley: 0\n  removed_later:\n    'false': 50\n";
    private const string NEW_DEFAULT = "name: Player{number}\ngame: Crystal Project\nCrystal Project:\n  goal:\n    astley: 50\n    true_astley: 0\n    clamshells: 0\n  skip_quizard_quiz:\n    'false': 50\n";

    private Game $game;
    private Run $run;
    private RunParticipant $participant;
    private SpyNotifier $notifier;
    /** @var list<object> */
    private array $dispatched = [];
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->configureApworld('hash-new.apworld', 'hash-new', 'Crystal Project', self::NEW_DEFAULT, new \DateTimeImmutable());
        $this->game->recordOptionTypes(['goal' => ['type' => 'choice', 'values' => ['astley', 'true_astley', 'clamshells']]]);
        $this->run = Run::create('owner-1', 'Soirée Crystal', new \DateTimeImmutable('2026-09-20'));
        $this->participant = RunParticipant::create($this->run->getId(), 'player-1', new \DateTimeImmutable('2026-09-20'));
        $this->notifier = new SpyNotifier();
    }

    public function testAnUntouchedSlotTakesTheNewDefaultAndGetsANewTest(): void
    {
        $this->slots(['slot-1' => str_replace('Player{number}', 'Jean', self::OLD_DEFAULT)]);

        $this->handle();

        $slot = $this->participant->getSlot('slot-1');
        self::assertSame(self::NEW_DEFAULT, $slot['playerYaml'] ?? null);
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertSame('pending', $slot['preflight']['status'] ?? null);
        self::assertEquals([new RunSlotPreflightJob($this->run->getId(), 'player-1', 'slot-1', hash('sha256', self::NEW_DEFAULT))], $this->dispatched);
        self::assertSame([], $this->notifier->sent);
        self::assertSame(1, $this->flushes);
    }

    public function testACustomisedSlotThatStillHoldsKeepsItsYamlAndGetsANewTest(): void
    {
        $custom = "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: true_astley\n";
        $this->slots(['slot-1' => $custom]);

        $this->handle();

        $slot = $this->participant->getSlot('slot-1');
        self::assertSame($custom, $slot['playerYaml'] ?? null, 'never rewritten, not even re-dumped');
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertArrayNotHasKey('needsReview', $slot);
        self::assertEquals([new RunSlotPreflightJob($this->run->getId(), 'player-1', 'slot-1', hash('sha256', $custom))], $this->dispatched);
        self::assertSame([], $this->notifier->sent);
    }

    public function testASlotThatNoLongerHoldsIsMarkedAndItsPlayerNotified(): void
    {
        $custom = "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: moon\n  removed_later: 'true'\n";
        $this->slots(['slot-1' => $custom]);

        $this->handle();

        $slot = $this->participant->getSlot('slot-1');
        self::assertSame($custom, $slot['playerYaml'] ?? null);
        self::assertSame('hash-new', $slot['apworldHash'] ?? null, 'the old version may be the broken one');
        self::assertSame([
            '« goal » : la valeur « moon » n\'est plus acceptée.',
            '« removed_later » n\'existe plus dans cette version.',
        ], $slot['needsReview'] ?? null);
        self::assertCount(1, $this->notifier->sent);
        self::assertSame('player-1', $this->notifier->sent[0]['recipientId']);
        self::assertSame('slot_yaml_needs_review', $this->notifier->sent[0]['type']);
        self::assertSame([
            'runId' => $this->run->getId(),
            'runTitle' => 'Soirée Crystal',
            'gameId' => $this->game->getId(),
            'gameName' => 'Crystal Project',
            'slotId' => 'slot-1',
            'reasons' => $slot['needsReview'],
        ], $this->notifier->sent[0]['payload']);
    }

    public function testAnUnreadableYamlIsKeptAndMarkedForReview(): void
    {
        $this->slots(['slot-1' => "game: [unclosed\n"]);

        $this->handle();

        self::assertSame(["game: [unclosed\n"], [$this->participant->getSlot('slot-1')['playerYaml'] ?? null]);
        self::assertSame(['Ton YAML n\'a pas pu être relu : vérifie-le avant la partie.'], $this->participant->getSlot('slot-1')['needsReview'] ?? null);
    }

    public function testOnlySlotsOfThisGameStillOnTheOldVersionAreTouched(): void
    {
        $this->participant->replaceSlots([
            ['slotId' => 'other-game', 'gameId' => 'game-other', 'playerYaml' => "game: Other\n", 'apworldHash' => 'hash-old'],
            ['slotId' => 'already-new', 'gameId' => $this->game->getId(), 'playerYaml' => "game: Crystal Project\n", 'apworldHash' => 'hash-new'],
        ]);

        $this->handle();

        self::assertSame('hash-old', $this->participant->getSlot('other-game')['apworldHash'] ?? null);
        self::assertSame([], $this->dispatched);
    }

    public function testASlotUpgradedWithoutTestNorReviewIsStillSaved(): void
    {
        // Story 38.7 review: no YAML on the slot and none in the new version - nothing to test, nothing to
        // review, but the slot did change apworld and must be saved.
        $this->game->configureApworld('hash-new.apworld', 'hash-new', 'Crystal Project', '', new \DateTimeImmutable());
        $this->slots(['slot-1' => '']);

        $this->handle();

        self::assertSame('hash-new', $this->participant->getSlot('slot-1')['apworldHash'] ?? null);
        self::assertSame([], $this->dispatched);
        self::assertSame(1, $this->flushes);
    }

    public function testALaunchedRunIsNeverTouched(): void
    {
        $this->slots(['slot-1' => "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: moon\n"]);
        $this->run->start(new \DateTimeImmutable());

        $this->handle();

        self::assertSame('hash-old', $this->participant->getSlot('slot-1')['apworldHash'] ?? null);
    }

    public function testAFailingRunDoesNotStopTheOthers(): void
    {
        $this->slots(['slot-1' => "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: true_astley\n"]);
        $broken = Run::create('owner-2', 'Cassée', new \DateTimeImmutable('2026-09-20'));

        $this->handle(runs: [$broken, $this->run], brokenRunId: $broken->getId());

        self::assertSame('hash-new', $this->participant->getSlot('slot-1')['apworldHash'] ?? null);
    }

    /**
     * @param array<string, string> $yamlBySlotId
     */
    private function slots(array $yamlBySlotId): void
    {
        $slots = [];
        foreach ($yamlBySlotId as $slotId => $yaml) {
            $slots[] = ['slotId' => $slotId, 'gameId' => $this->game->getId(), 'playerYaml' => $yaml, 'apworldHash' => 'hash-old'];
        }
        $this->participant->replaceSlots($slots);
    }

    /**
     * @param list<Run>|null $runs
     */
    private function handle(?array $runs = null, ?string $brokenRunId = null): void
    {
        $runRepository = self::createStub(RunRepositoryInterface::class);
        $runRepository->method('findByStatuses')->willReturn($runs ?? [$this->run]);

        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunId')->willReturnCallback(function (string $runId) use ($brokenRunId): array {
            if ($runId === $brokenRunId) {
                throw new \RuntimeException('database hiccup');
            }

            return $runId === $this->run->getId() ? [$this->participant] : [];
        });
        $participants->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        new UpgradeRunSlotsAfterApworldPromotionHandler($runRepository, $participants, $games, $this->notifier, $bus, new MockClock('2026-09-26 04:20:00+00:00'), new NullLogger())(
            new ApworldPromoted($this->game->getId(), 'hash-old', 'hash-new', self::OLD_DEFAULT),
        );
    }
}
