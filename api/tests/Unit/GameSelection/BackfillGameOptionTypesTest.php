<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\BackfillGameOptionTypes;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BackfillGameOptionTypesTest extends TestCase
{
    public function testBackfillsOnlyApworldGamesWithBounds(): void
    {
        $now = new \DateTimeImmutable('2026-06-12T10:00:00+00:00');

        $withApworld = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $withApworld->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $noApworld = Game::create('Draft Game', 'draft-game', '', null, '', '', 'available', $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchOptionTypes')->willReturnMap([
            ['hash-md', ['song_difficulty_min' => ['min' => 1, 'max' => 11, 'default' => 4]]],
        ]);

        $saved = [];
        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$withApworld, $noApworld]);
        $repo->method('save')->willReturnCallback(function (Game $g) use (&$saved): void {
            $saved[] = $g;
        });

        $result = new BackfillGameOptionTypes($repo, $runner, new NullLogger())->run();

        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->updated);
        self::assertCount(1, $saved);
        self::assertSame(['song_difficulty_min' => ['min' => 1, 'max' => 11, 'default' => 4]], $withApworld->getOptionTypes());
        self::assertNull($noApworld->getOptionTypes());
    }

    /**
     * Story 9.53. Re-reading alone is not enough once the introspection itself changes: the sidecar
     * of an existing game was written by the previous image, and reading it again returns the same
     * answer. The option asks the runner to regenerate it first - and the order is the whole point.
     */
    public function testReintrospectRunsBeforeReadingTheTypes(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $game = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $game->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $calls = [];
        // A stub, not a mock: the order is asserted on $calls below, so configuring expectations
        // here would state the same thing twice and less legibly.
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('reintrospectApworld')->willReturnCallback(function (string $hash) use (&$calls): bool {
            $calls[] = 'reintrospect:'.$hash;

            return true;
        });
        $runner->method('fetchOptionTypes')->willReturnCallback(function (string $hash) use (&$calls): array {
            $calls[] = 'fetch:'.$hash;

            return ['goal' => ['type' => 'choice']];
        });

        $result = new BackfillGameOptionTypes($this->repository([$game]), $runner, new NullLogger())->run(true);

        self::assertSame(['reintrospect:hash-md', 'fetch:hash-md'], $calls);
        self::assertSame(1, $result->reintrospected);
        self::assertSame(0, $result->reintrospectionFailed);
        self::assertSame(1, $result->updated);
    }

    public function testWithoutTheOptionNothingIsReintrospected(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $game = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $game->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $runner = self::createMock(RunnerGatewayInterface::class);
        $runner->expects(self::never())->method('reintrospectApworld');
        $runner->method('fetchOptionTypes')->willReturn(['goal' => ['type' => 'choice']]);

        $result = new BackfillGameOptionTypes($this->repository([$game]), $runner, new NullLogger())->run();

        self::assertSame(0, $result->reintrospected);
        self::assertSame(1, $result->updated);
    }

    public function testAFailedReintrospectionStillReadsThePreviousAnswer(): void
    {
        // The runner leaves the sidecar intact on failure, so the game keeps the types it had -
        // stale, but complete. Aborting here would be worse than carrying on.
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $game = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $game->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('reintrospectApworld')->willReturn(false);
        $runner->method('fetchOptionTypes')->willReturn(['goal' => ['type' => 'choice']]);

        $result = new BackfillGameOptionTypes($this->repository([$game]), $runner, new NullLogger())->run(true);

        self::assertSame(0, $result->reintrospected);
        self::assertSame(1, $result->reintrospectionFailed);
        self::assertSame(1, $result->updated, 'the previous introspection is still worth reading');
    }

    public function testASlugLimitsTheSweepToOneGame(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $target = Game::create('Muse Dash', 'muse-dash', '', null, '', '', 'available', $now);
        $target->configureApworld('key.apworld', 'hash-md', 'Muse Dash', 'game: Muse Dash', $now);
        $other = Game::create('Atlyss', 'atlyss', '', null, '', '', 'available', $now);
        $other->configureApworld('key2.apworld', 'hash-at', 'Atlyss', 'game: Atlyss', $now);

        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$target, $other]);
        $repo->method('findBySlug')->willReturnMap([['muse-dash', $target]]);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('reintrospectApworld')->willReturn(true);
        $runner->method('fetchOptionTypes')->willReturn(['goal' => ['type' => 'choice']]);

        $result = new BackfillGameOptionTypes($repo, $runner, new NullLogger())->run(true, 'muse-dash');

        self::assertSame(1, $result->processed);
        self::assertNull($other->getOptionTypes());
    }

    public function testAnUnknownSlugSweepsNothing(): void
    {
        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $runner = self::createMock(RunnerGatewayInterface::class);
        $runner->expects(self::never())->method('fetchOptionTypes');

        $result = new BackfillGameOptionTypes($repo, $runner, new NullLogger())->run(true, 'nope');

        self::assertSame(0, $result->processed);
    }

    /** @param list<Game> $games */
    private function repository(array $games): GameRepositoryInterface
    {
        $repo = self::createStub(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn($games);

        return $repo;
    }
}
