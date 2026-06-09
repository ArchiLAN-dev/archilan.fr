<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\WeeklyRuns\Application\Handler\GenerateWeeklyRunsMessageHandler;
use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class GenerateWeeklyRunsMessageHandlerTest extends TestCase
{
    use SessionConfigDefaultsTrait;

    private static \DateTimeImmutable $defaultNow;

    public static function setUpBeforeClass(): void
    {
        self::$defaultNow = new \DateTimeImmutable('2026-05-18T00:00:00', new \DateTimeZone('UTC'));
    }

    public function testInvokeSkipsTemplateWhenRunAlreadyExistsForCurrentWeek(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(true);
        $runs->expects(self::never())->method('save');
        $runs->expects(self::never())->method('flush');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), $this->makeGame(), $generator)
            ->__invoke(new GenerateWeeklyRunsMessage());
    }

    public function testInvokeCreatesWeeklyRunWhenNoneExistsForCurrentWeek(): void
    {
        /** @var WeeklyRun|null $saved */
        $saved = null;

        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->expects(self::once())->method('save')->willReturnCallback(static function (WeeklyRun $run) use (&$saved): void {
            $saved = $run;
        });

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::once())->method('generate');

        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), $this->makeGame(), $generator)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $saved);
        self::assertSame(WeeklyRun::STATUS_ACTIVE, $saved->getStatus());
        self::assertSame('template-1', $saved->getTemplateId());
        // Not launchable yet: the run becomes generated only via the webhook.
        self::assertNull($saved->getGeneratedOutputKey());
    }

    public function testInvokeSeedIsRandomPositiveInteger(): void
    {
        /** @var WeeklyRun|null $saved */
        $saved = null;

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->method('save')->willReturnCallback(static function (WeeklyRun $run) use (&$saved): void {
            $saved = $run;
        });

        $generator = $this->createStub(WeeklyRunGeneratorInterface::class);
        $generator->method('generate'); // void dispatch

        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), $this->makeGame(), $generator)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $saved);
        self::assertMatchesRegularExpression('/^\d+$/', $saved->getSeed());
        self::assertGreaterThan(0, (int) $saved->getSeed());
    }

    public function testInvokeUsesIsoYearNotCalendarYearAtYearBoundary(): void
    {
        // 2027-01-01 is calendar year 2027 but ISO year 2026 (ISO week 53).
        $boundaryDate = new \DateTimeImmutable('2027-01-01T00:00:00', new \DateTimeZone('UTC'));
        $expectedYear = (int) $boundaryDate->format('o');
        $expectedWeek = (int) $boundaryDate->format('W');

        /** @var WeeklyRun|null $saved */
        $saved = null;

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->method('save')->willReturnCallback(static function (WeeklyRun $run) use (&$saved): void {
            $saved = $run;
        });

        $generator = $this->createStub(WeeklyRunGeneratorInterface::class);
        $generator->method('generate'); // void dispatch

        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), $this->makeGame(), $generator, $boundaryDate)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $saved);
        self::assertSame($expectedYear, $saved->getWeekYear());
        self::assertSame($expectedWeek, $saved->getWeekNumber());
        self::assertNotSame((int) $boundaryDate->format('Y'), $saved->getWeekYear());
    }

    public function testInvokeSkipsTemplateWhenGameNotFound(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->expects(self::never())->method('save');
        $runs->expects(self::never())->method('flush');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), null, $generator)
            ->__invoke(new GenerateWeeklyRunsMessage());
    }

    public function testInvokeDoesNotThrowWhenDispatchFails(): void
    {
        /** @var WeeklyRun|null $saved */
        $saved = null;

        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->expects(self::once())->method('save')->willReturnCallback(static function (WeeklyRun $run) use (&$saved): void {
            $saved = $run;
        });

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('orchestrator unreachable'));

        // A failed dispatch is logged, not thrown: it leaves the run not-launchable
        // and must not abort the other templates.
        $this->makeHandler($runs, $this->makeTemplateRepo(['template-1']), $this->makeGame(), $generator)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $saved);
        self::assertNull($saved->getGeneratedOutputKey());
    }

    /**
     * @param list<string> $templateIds
     */
    private function makeTemplateRepo(array $templateIds): WeeklyTemplateRepositoryInterface
    {
        $now = new \DateTimeImmutable();
        $templates = array_map(
            static fn (string $id) => new WeeklyTemplate($id, 'game-1', "name: ArchiLAN\ngame: Archipelago\n", null, null, true, $now, $now),
            $templateIds,
        );

        $repo = $this->createStub(WeeklyTemplateRepositoryInterface::class);
        $repo->method('findAllActive')->willReturn($templates);

        return $repo;
    }

    private function makeGame(): Game
    {
        $game = $this->createStub(Game::class);
        $game->method('getApworldStorageKey')->willReturn('apworlds/archipelago.apworld');

        return $game;
    }

    private function makeHandler(
        WeeklyRunRepositoryInterface $runs,
        WeeklyTemplateRepositoryInterface $templates,
        ?Game $game,
        WeeklyRunGeneratorInterface $generator,
        ?\DateTimeImmutable $now = null,
    ): GenerateWeeklyRunsMessageHandler {
        $games = $this->createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);

        return new GenerateWeeklyRunsMessageHandler(
            $runs,
            $templates,
            $games,
            $generator,
            new MockClock($now ?? self::$defaultNow),
            new NullLogger(),
            $this->defaultsResolver(),
        );
    }
}
