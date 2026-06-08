<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\WeeklyRuns\Application\GenerateWeeklyRunForTemplate;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class GenerateWeeklyRunForTemplateTest extends TestCase
{
    private const NOW = '2026-05-18T00:00:00';

    public function testGenerateCreatesRunAndDispatchesWhenNoneExists(): void
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

        $this->makeService($runs, $this->makeTemplate('template-1'), $this->makeGame(), $generator)
            ->generate('template-1');

        self::assertInstanceOf(WeeklyRun::class, $saved);
        self::assertSame('template-1', $saved->getTemplateId());
        self::assertSame(WeeklyRun::STATUS_ACTIVE, $saved->getStatus());
        // Not launchable yet: only the session.generated webhook sets the output key.
        self::assertNull($saved->getGeneratedOutputKey());
        self::assertMatchesRegularExpression('/^\d+$/', $saved->getSeed());
    }

    public function testGenerateThrowsRunAlreadyExists(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(true);
        $runs->expects(self::never())->method('save');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('run_already_exists');

        $this->makeService($runs, $this->makeTemplate('template-1'), $this->makeGame(), $generator)
            ->generate('template-1');
    }

    public function testGenerateThrowsTemplateNotFound(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->expects(self::never())->method('save');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('template_not_found');

        $this->makeService($runs, null, $this->makeGame(), $generator)
            ->generate('missing');
    }

    public function testGenerateThrowsTemplateIncompleteWhenGameMissing(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->expects(self::never())->method('save');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('template_incomplete');

        $this->makeService($runs, $this->makeTemplate('template-1'), null, $generator)
            ->generate('template-1');
    }

    public function testGenerateThrowsTemplateIncompleteWhenYamlEmpty(): void
    {
        $runs = $this->createMock(WeeklyRunRepositoryInterface::class);
        $runs->method('existsByTemplateAndWeek')->willReturn(false);
        $runs->expects(self::never())->method('save');

        $generator = $this->createMock(WeeklyRunGeneratorInterface::class);
        $generator->expects(self::never())->method('generate');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('template_incomplete');

        $this->makeService($runs, $this->makeTemplate('template-1', ''), $this->makeGame(), $generator)
            ->generate('template-1');
    }

    private function makeTemplate(string $id, string $yaml = "name: ArchiLAN\ngame: Archipelago\n"): WeeklyTemplate
    {
        $now = new \DateTimeImmutable();

        return new WeeklyTemplate($id, 'game-1', $yaml, null, null, true, $now, $now);
    }

    private function makeGame(): Game
    {
        $game = $this->createStub(Game::class);
        $game->method('getApworldStorageKey')->willReturn('apworlds/archipelago.apworld');

        return $game;
    }

    private function makeService(
        WeeklyRunRepositoryInterface $runs,
        ?WeeklyTemplate $template,
        ?Game $game,
        WeeklyRunGeneratorInterface $generator,
    ): GenerateWeeklyRunForTemplate {
        $templates = $this->createStub(WeeklyTemplateRepositoryInterface::class);
        $templates->method('findById')->willReturn($template);

        $games = $this->createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);

        return new GenerateWeeklyRunForTemplate(
            $templates,
            $runs,
            $games,
            $generator,
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
            new NullLogger(),
        );
    }
}
