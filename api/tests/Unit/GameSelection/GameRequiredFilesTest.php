<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\RecordGenerationRequiredFile;
use App\GameSelection\Application\Command\SyncGameDeclaredFiles;
use App\GameSelection\Application\Support\GenerationFiles;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Support\RequiredGenerationFile;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Story 44.3: what a world declares it reads from the player, and what a failed generation proved it needs.
 */
final class GameRequiredFilesTest extends TestCase
{
    private const array DKC3_ROM = ['setting' => 'DK3Settings.rom_file', 'file' => 'dkc3.sfc', 'extension' => '.sfc', 'description' => 'DKC3 (US) ROM File', 'md5s' => ['120abf304f0c40fe059f6a192ed4f947']];

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
    }

    public function testADeclaredFileIsNotNeededUntilAGenerationProvesIt(): void
    {
        $game = $this->catalogGame('Donkey Kong Country 3');

        $game->recordDeclaredRequiredFiles([self::DKC3_ROM]);

        self::assertSame([[...self::DKC3_ROM, 'neededAtGeneration' => false]], $game->getRequiredFiles());
        self::assertTrue($game->knowsRequiredFile('DK3Settings.rom_file'));
    }

    public function testAProvenFileSurvivesAReintrospection(): void
    {
        $game = $this->catalogGame('Donkey Kong Country 3');
        $game->recordDeclaredRequiredFiles([self::DKC3_ROM]);
        self::assertTrue($game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []));
        self::assertFalse($game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []), 'idempotent');

        $game->recordDeclaredRequiredFiles([['setting' => 'DK3Settings.rom_file', 'file' => 'new.sfc', 'extension' => '.sfc', 'description' => null, 'md5s' => []]]);
        self::assertTrue($game->getRequiredFiles()[0]['neededAtGeneration']);

        // Even when the world stops declaring it: the failed generation is the stronger evidence.
        $game->recordDeclaredRequiredFiles([]);
        self::assertSame('DK3Settings.rom_file', $game->getRequiredFiles()[0]['setting']);
        self::assertTrue($game->getRequiredFiles()[0]['neededAtGeneration']);
    }

    public function testMarkingKeepsTheDeclaredDetailsTheFailureLacks(): void
    {
        $game = $this->catalogGame('Donkey Kong Country 3');
        $game->recordDeclaredRequiredFiles([self::DKC3_ROM]);

        $game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []);

        $file = $game->getRequiredFiles()[0];
        self::assertSame('DKC3 (US) ROM File', $file['description']);
        self::assertSame(['120abf304f0c40fe059f6a192ed4f947'], $file['md5s']);
    }

    public function testAnAdminCanClearAFalsePositive(): void
    {
        $game = $this->catalogGame('Donkey Kong Country 3');
        $game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []);

        self::assertTrue($game->clearRequiredAtGeneration('DK3Settings.rom_file'));
        self::assertFalse($game->getRequiredFiles()[0]['neededAtGeneration']);
        self::assertFalse($game->clearRequiredAtGeneration('DK3Settings.rom_file'));
    }

    public function testTheSyncReadsTheApworldOrTheImageListing(): void
    {
        $catalog = $this->catalogGame('Donkey Kong Country 3');
        $official = new Game('g-oot', 'Ocarina of Time', 'oot', '', null, '', '', Game::AVAILABILITY_AVAILABLE, 'Ocarina of Time', new \DateTimeImmutable(), new \DateTimeImmutable());
        NullRunnerGateway::$declaredFiles = ['hash-dkc3' => [self::DKC3_ROM]];
        NullRunnerGateway::$officialDeclaredFiles = ['Ocarina of Time' => [['setting' => 'OOTSettings.rom_file', 'file' => 'oot.z64', 'extension' => '.z64', 'description' => null, 'md5s' => []]]];

        $report = $this->sync([$catalog, $official])->syncAll();

        self::assertTrue($report->runnerAvailable);
        self::assertSame(2, $report->updated);
        self::assertSame('DK3Settings.rom_file', $catalog->getRequiredFiles()[0]['setting']);
        self::assertSame('OOTSettings.rom_file', $official->getRequiredFiles()[0]['setting']);
    }

    public function testASilentRunnerChangesNothing(): void
    {
        $catalog = $this->catalogGame('Donkey Kong Country 3');
        $catalog->recordDeclaredRequiredFiles([self::DKC3_ROM]);
        NullRunnerGateway::$officialDeclaredFiles = null;

        $report = $this->sync([$catalog])->syncAll();

        self::assertFalse($report->runnerAvailable);
        self::assertCount(1, $catalog->getRequiredFiles());
    }

    public function testTheFailureMarksTheGameThatDeclaresTheSetting(): void
    {
        $dkc3 = $this->catalogGame('Donkey Kong Country 3');
        $dkc3->recordDeclaredRequiredFiles([self::DKC3_ROM]);
        $other = $this->catalogGame('Hollow Knight');

        $marked = $this->recorder([$dkc3, $other])->record([$dkc3->getId(), $other->getId()], $this->failure());

        self::assertSame($dkc3->getId(), $marked);
        self::assertTrue($dkc3->getRequiredFiles()[0]['neededAtGeneration']);
        self::assertSame([], $other->getRequiredFiles());
    }

    public function testALoneGameIsTheCulpritEvenUndeclared(): void
    {
        $lone = $this->catalogGame('Donkey Kong Country 3');

        self::assertSame($lone->getId(), $this->recorder([$lone])->record([$lone->getId()], $this->failure()));
        self::assertTrue($lone->getRequiredFiles()[0]['neededAtGeneration']);
    }

    public function testSeveralUndeclaringGamesMarkNothing(): void
    {
        $a = $this->catalogGame('A');
        $b = $this->catalogGame('B');

        self::assertNull($this->recorder([$a, $b])->record([$a->getId(), $b->getId()], $this->failure()));
        self::assertSame([], $a->getRequiredFiles());
    }

    public function testAPartyAsksForEachSettingOnce(): void
    {
        $first = $this->catalogGame('Donkey Kong Country 3');
        $first->recordDeclaredRequiredFiles([self::DKC3_ROM]);
        $second = $this->catalogGame('DKC3 fork');
        $second->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []);

        $files = GenerationFiles::forGames([$first, $second]);

        self::assertCount(1, $files);
        self::assertTrue($files[0]['neededAtGeneration']);
        self::assertSame(['Donkey Kong Country 3', 'DKC3 fork'], $files[0]['games']);
    }

    private function catalogGame(string $name): Game
    {
        $game = Game::create($name, strtolower(str_replace(' ', '-', $name)), '', null, '', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        if ('Donkey Kong Country 3' === $name) {
            $game->configureApworld('k', 'hash-dkc3', $name, '', new \DateTimeImmutable());
        }

        return $game;
    }

    private function failure(): RequiredGenerationFile
    {
        $file = RequiredGenerationFile::fromRecord(['setting' => 'DK3Settings.rom_file', 'file' => 'dkc3.sfc', 'extension' => '.sfc']);
        self::assertNotNull($file);

        return $file;
    }

    /** @param list<Game> $games */
    private function repository(array $games): GameRepositoryInterface
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findAllSortedByName')->willReturn($games);
        $repository->method('findByIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_filter($games, static fn (Game $game): bool => \in_array($game->getId(), $ids, true))),
        );

        return $repository;
    }

    /** @param list<Game> $games */
    private function sync(array $games): SyncGameDeclaredFiles
    {
        return new SyncGameDeclaredFiles($this->repository($games), new NullRunnerGateway(), new NullLogger());
    }

    /** @param list<Game> $games */
    private function recorder(array $games): RecordGenerationRequiredFile
    {
        return new RecordGenerationRequiredFile($this->repository($games), new NullLogger());
    }
}
