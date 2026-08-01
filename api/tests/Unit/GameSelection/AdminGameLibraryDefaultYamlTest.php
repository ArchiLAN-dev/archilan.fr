<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Port\IgdbHttpClientInterface;
use App\GameSelection\Application\Query\AdminGameListQueryInterface;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Application\Support\GamePlatformResolver;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Stories 9.45/9.46: the default template is what players receive (it seeds every new slot
 * and is the launch fallback), so an admin must be able to fix an invalid generated one -
 * and get back to the generated version.
 */
final class AdminGameLibraryDefaultYamlTest extends TestCase
{
    private const string VALID_YAML = "name: Player{number}\ngame: Atlyss\nAtlyss:\n  main_class: fighter\n";

    public function testSaveDefaultYamlStoresItAndSyncsTheStoredTemplate(): void
    {
        $game = $this->game();
        $runner = self::createMock(RunnerGatewayInterface::class);
        $runner->expects(self::once())->method('setApworldTemplate')
            ->with('deadbeef', self::VALID_YAML)->willReturn(true);
        // The verdict must describe the template we now serve.
        $runner->expects(self::once())->method('runApworldPreflight')->with('deadbeef')->willReturn(true);

        $result = $this->library($game, $runner)->saveDefaultYaml($game->getId(), self::VALID_YAML);

        self::assertSame([], $result['errors']);
        self::assertSame(self::VALID_YAML, $game->getDefaultYaml());
        self::assertArrayNotHasKey('warning', $result);
    }

    public function testSaveDefaultYamlKeepsTheValueWhenTheRunnerIsDown(): void
    {
        $game = $this->game();
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('setApworldTemplate')->willReturn(false);

        $result = $this->library($game, $runner)->saveDefaultYaml($game->getId(), self::VALID_YAML);

        // Players get the fixed template even if the verdict cannot be refreshed.
        self::assertSame([], $result['errors']);
        self::assertSame(self::VALID_YAML, $game->getDefaultYaml());
        self::assertArrayHasKey('warning', $result);
    }

    public function testSaveDefaultYamlRejectsUnparseableYaml(): void
    {
        $game = $this->game();

        $result = $this->library($game)->saveDefaultYaml($game->getId(), "game: [unclosed\n");

        self::assertArrayHasKey('defaultYaml', $result['errors']);
        self::assertNull($game->getDefaultYaml());
    }

    public function testSaveDefaultYamlRejectsAMissingGameField(): void
    {
        $game = $this->game();

        $result = $this->library($game)->saveDefaultYaml($game->getId(), "name: Player1\n");

        self::assertStringContainsString('game', $result['errors']['defaultYaml'][0]);
    }

    public function testSaveDefaultYamlRejectsAGameFieldForAnotherWorld(): void
    {
        $game = $this->game();

        $result = $this->library($game)->saveDefaultYaml($game->getId(), "name: Player1\ngame: Timespinner\n");

        self::assertStringContainsString('Atlyss', $result['errors']['defaultYaml'][0]);
    }

    public function testSaveDefaultYamlRejectsAnEmptyTemplate(): void
    {
        $game = $this->game();

        $result = $this->library($game)->saveDefaultYaml($game->getId(), "   \n");

        self::assertArrayHasKey('defaultYaml', $result['errors']);
    }

    public function testRegenerateReplacesTheTemplateAndRefreshesDerivedData(): void
    {
        $game = $this->game();
        $game->overrideDefaultYaml("game: Atlyss\n# edited by hand\n", new \DateTimeImmutable());

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('regenerateApworldTemplate')->willReturn(['template' => self::VALID_YAML]);
        $runner->method('fetchOptionTypes')->willReturn(['logic' => ['min' => 0, 'max' => 5, 'default' => 1]]);
        $runner->method('fetchLocationNames')->willReturn(['Boss Reward']);

        $result = $this->library($game, $runner)->regenerateDefaultYaml($game->getId());

        self::assertSame([], $result['errors']);
        self::assertSame(self::VALID_YAML, $game->getDefaultYaml());
        self::assertSame(['Boss Reward'], $game->getLocationNames());
    }

    public function testRegenerateFailureLeavesTheStoredTemplateUntouched(): void
    {
        $game = $this->game();
        $game->overrideDefaultYaml(self::VALID_YAML, new \DateTimeImmutable());

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('regenerateApworldTemplate')->willReturn([
            'error' => "Traceback...\nOptionError: broken world",
        ]);

        $result = $this->library($game, $runner)->regenerateDefaultYaml($game->getId());

        self::assertArrayHasKey('apworld', $result['errors']);
        self::assertStringContainsString('OptionError: broken world', $result['errors']['apworld'][0]);
        self::assertSame(self::VALID_YAML, $game->getDefaultYaml(), 'a failure must never blank a working template');
    }

    private function game(): Game
    {
        $now = new \DateTimeImmutable('2026-08-01T10:00:00+00:00');
        $game = Game::create('Atlyss', 'atlyss', 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('deadbeef.apworld', 'deadbeef', 'Atlyss', '', $now);
        // configureApworld seeds an empty template: exactly the state a failed generation leaves.
        $game->overrideDefaultYaml('', $now);
        $reset = new \ReflectionProperty($game, 'defaultYaml');
        $reset->setValue($game, null);

        return $game;
    }

    private function library(Game $game, ?RunnerGatewayInterface $runner = null): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            self::createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner ?? self::createStub(RunnerGatewayInterface::class),
            self::createStub(MinioStorageInterface::class),
            new MockClock(),
            'apworlds',
            new ApworldVersionChecker(new MockHttpClient([]), new NullLogger(), 'token'),
            $usage,
            new GamePlatformResolver(self::createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder(self::createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader(),
        );
    }
}
