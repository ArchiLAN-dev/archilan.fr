<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameIntrospection;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;

/**
 * Story 38.20: a list of games must not load their introspection. With item names added (v0.44.0), decoding it
 * for the whole catalogue ran PHP out of memory on /admin/catalogue, the run picker and the backfills.
 */
final class GameIntrospectionLazyLoadTest extends FunctionalTestCase
{
    public function testListingTheCatalogueLoadsNoIntrospection(): void
    {
        $this->introspected('Muse Dash', 'muse-dash');
        $this->introspected('Hollow Knight', 'hollow-knight');
        $this->createGame('Draft Game', 'draft-game');
        $this->entityManager->clear();

        $games = $this->repository()->findAllSortedByName();

        self::assertCount(3, $games);
        foreach ($games as $game) {
            $introspection = $this->introspectionOf($game);
            self::assertTrue(
                null === $introspection || $this->entityManager->getUnitOfWork()->isUninitializedObject($introspection),
                $game->getName().' had its introspection loaded by a list',
            );
        }
    }

    public function testTheIntrospectionLoadsOnFirstReadAndSurvivesARoundTrip(): void
    {
        $id = $this->introspected('Muse Dash', 'muse-dash')->getId();
        $this->entityManager->clear();

        $game = $this->repository()->findById($id);

        self::assertInstanceOf(Game::class, $game);
        self::assertSame("game: Muse Dash\n", $game->getDefaultYaml());
        self::assertSame(['goal' => ['type' => 'choice', 'values' => ['clear']]], $game->getOptionTypes());
        self::assertSame(['Chest 1', 'Chest 2'], $game->getLocationNames());
        self::assertSame(['Song 1'], $game->getItemNames());
        self::assertSame(['Songs' => ['Song 1']], $game->getItemGroups());
    }

    public function testAGameWithoutIntrospectionGetsNoRowUntilSomethingIsRecorded(): void
    {
        $game = $this->createGame('Draft Game', 'draft-game');
        $game->recordLocationNames([]);
        $game->recordOptionTypes(null);
        $this->repository()->save($game);

        self::assertNull($this->introspectionOf($game));
        self::assertSame(0, $this->entityManager->getRepository(GameIntrospection::class)->count([]));

        $game->recordLocationNames(['Chest 1']);
        $this->repository()->save($game);
        $this->entityManager->clear();

        self::assertSame(['Chest 1'], $this->repository()->findById($game->getId())?->getLocationNames());
    }

    public function testDeletingAGameDeletesItsIntrospection(): void
    {
        $game = $this->introspected('Muse Dash', 'muse-dash');

        $this->repository()->remove($game);

        self::assertSame(0, $this->entityManager->getRepository(GameIntrospection::class)->count([]));
    }

    public function testAReleasedGameLeavesTheNextFlushWorking(): void
    {
        $game = $this->introspected('Muse Dash', 'muse-dash');
        $other = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->repository()->release($game);
        $other->recordLocationNames(['Crossroads']);
        $this->repository()->save($other);

        self::assertFalse($this->entityManager->contains($game));
        self::assertSame(['Crossroads'], $this->repository()->findById($other->getId())?->getLocationNames());
    }

    private function introspected(string $name, string $slug): Game
    {
        $game = $this->createGame($name, $slug);
        $game->configureApworld('key.apworld', 'hash-'.$slug, $name, "\u{FEFF}game: {$name}\n", new \DateTimeImmutable('2026-10-22T10:00:00+00:00'));
        $game->recordOptionTypes(['goal' => ['type' => 'choice', 'values' => ['clear']]]);
        $game->recordLocationNames(['Chest 1', 'Chest 2']);
        $game->recordItemNames(['Song 1'], ['Songs' => ['Song 1']]);
        $this->repository()->save($game);

        return $game;
    }

    private function introspectionOf(Game $game): ?object
    {
        $value = new \ReflectionProperty(Game::class, 'introspection')->getValue($game);

        return \is_object($value) ? $value : null;
    }

    private function repository(): GameRepositoryInterface
    {
        $repository = self::getContainer()->get(GameRepositoryInterface::class);
        self::assertInstanceOf(GameRepositoryInterface::class, $repository);

        return $repository;
    }
}
