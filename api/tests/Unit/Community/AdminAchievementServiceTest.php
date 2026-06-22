<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\AchievementImageUrlResolver;
use App\Community\Application\AdminAchievementService;
use App\Community\Application\EventCatalogueQueryInterface;
use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\InvalidAchievementRuleException;
use App\Shared\Infrastructure\MinioStorageInterface;
use PHPUnit\Framework\TestCase;

final class AdminAchievementServiceTest extends TestCase
{
    public function testCreatePersistsAndPositionsAfterMax(): void
    {
        $service = new AdminAchievementService($repo = $this->repo(), $this->events(), $this->imageUrls());

        $created = $service->create([
            'key' => 'night_owl',
            'name' => 'Couche-tard',
            'description' => 'Jouer la nuit.',
            'rule' => $this->simpleRule(),
        ]);

        self::assertSame('night_owl', $created['key']);
        self::assertTrue($created['active']);
        self::assertSame(0, $created['position']);
        self::assertCount(1, $repo->all());
    }

    public function testCreateRejectsDuplicateKey(): void
    {
        $service = new AdminAchievementService($this->repo([$this->definition('first_run')]), $this->events(), $this->imageUrls());

        $this->expectException(\InvalidArgumentException::class);
        $service->create(['key' => 'first_run', 'name' => 'X', 'rule' => $this->simpleRule()]);
    }

    public function testCreateRejectsInvalidKey(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events(), $this->imageUrls());

        $this->expectException(\InvalidArgumentException::class);
        $service->create(['key' => 'Bad Key!', 'name' => 'X', 'rule' => $this->simpleRule()]);
    }

    public function testCreateRejectsMissingName(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events(), $this->imageUrls());

        $this->expectException(\InvalidArgumentException::class);
        $service->create(['key' => 'ok_key', 'name' => '  ', 'rule' => $this->simpleRule()]);
    }

    public function testCreateRejectsMalformedRule(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events(), $this->imageUrls());

        $this->expectException(InvalidAchievementRuleException::class);
        $service->create(['key' => 'ok_key', 'name' => 'X', 'rule' => ['op' => 'all', 'rules' => []]]);
    }

    public function testCreateAcceptsScopedEventFactForRealEvent(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events(['evt-1']), $this->imageUrls());

        $created = $service->create([
            'key' => 'archilan3',
            'name' => 'ArchiLAN #3',
            'rule' => $this->scopedRule('evt-1'),
        ]);

        self::assertSame('archilan3', $created['key']);
    }

    public function testCreateRejectsScopedEventFactForUnknownEvent(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events([]), $this->imageUrls());

        $this->expectException(InvalidAchievementRuleException::class);
        $service->create(['key' => 'ghost_event', 'name' => 'X', 'rule' => $this->scopedRule('nope')]);
    }

    public function testUpdateUnknownIdReturnsNull(): void
    {
        $service = new AdminAchievementService($this->repo(), $this->events(), $this->imageUrls());

        self::assertNull($service->update('missing', ['name' => 'X', 'rule' => $this->simpleRule()]));
    }

    public function testUpdateKeepsKeyImmutable(): void
    {
        $definition = $this->definition('first_run');
        $service = new AdminAchievementService($this->repo([$definition]), $this->events(), $this->imageUrls());

        $result = $service->update($definition->getId(), [
            'key' => 'attempted_rename',
            'name' => 'Renommé',
            'rule' => $this->simpleRule(),
        ]);

        self::assertNotNull($result);
        self::assertSame('first_run', $result['key']);
        self::assertSame('Renommé', $result['name']);
    }

    public function testSetActiveAndReorder(): void
    {
        $a = $this->definition('a');
        $b = $this->definition('b');
        $service = new AdminAchievementService($this->repo([$a, $b]), $this->events(), $this->imageUrls());

        self::assertTrue($service->setActive($a->getId(), false));
        self::assertFalse($a->isActive());
        self::assertFalse($service->setActive('missing', true));

        $service->reorder([$b->getId(), $a->getId()]);
        self::assertSame(0, $b->getPosition());
        self::assertSame(1, $a->getPosition());
    }

    public function testFormOptionsExposesFactsOperatorsGroupsAndEvents(): void
    {
        $options = (new AdminAchievementService($this->repo(), $this->events(['evt-1']), $this->imageUrls()))->formOptions();

        self::assertNotEmpty($options['facts']);
        self::assertContains('eventsWithGoal', array_map(static fn (array $f): string => $f['key'], $options['facts']));
        self::assertContains('>=', $options['operators']);
        self::assertContains('between', $options['operators']);
        self::assertSame(['all', 'any', 'none'], $options['groupOps']);
        self::assertSame([['id' => 'evt-1', 'title' => 'Event evt-1']], $options['events']);
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleRule(): array
    {
        return ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function scopedRule(string $eventId): array
    {
        return ['op' => 'all', 'rules' => [['fact' => 'event_goal:'.$eventId, 'operator' => '>=', 'value' => 1]]];
    }

    private function definition(string $key): AchievementDefinition
    {
        return AchievementDefinition::create($key, ucfirst($key), '', $this->simpleRule(), 0, new \DateTimeImmutable());
    }

    /**
     * @param list<string> $knownIds
     */
    private function events(array $knownIds = []): EventCatalogueQueryInterface
    {
        return new class($knownIds) implements EventCatalogueQueryInterface {
            /** @param list<string> $knownIds */
            public function __construct(private array $knownIds)
            {
            }

            public function selectableEvents(): array
            {
                return array_map(static fn (string $id): array => ['id' => $id, 'title' => 'Event '.$id], $this->knownIds);
            }

            public function exists(string $eventId): bool
            {
                return \in_array($eventId, $this->knownIds, true);
            }
        };
    }

    private function imageUrls(): AchievementImageUrlResolver
    {
        // The unit tests use no custom image, so the resolver short-circuits and never touches storage.
        return new AchievementImageUrlResolver($this->createStub(MinioStorageInterface::class), 'media', 3600);
    }

    /**
     * @param list<AchievementDefinition> $seed
     */
    private function repo(array $seed = []): AchievementDefinitionRepositoryInterface
    {
        return new class($seed) implements AchievementDefinitionRepositoryInterface {
            /** @param list<AchievementDefinition> $defs */
            public function __construct(private array $defs)
            {
            }

            public function allActive(): array
            {
                return array_values(array_filter($this->defs, static fn (AchievementDefinition $d): bool => $d->isActive()));
            }

            public function all(): array
            {
                return $this->defs;
            }

            public function findById(string $id): ?AchievementDefinition
            {
                foreach ($this->defs as $d) {
                    if ($d->getId() === $id) {
                        return $d;
                    }
                }

                return null;
            }

            public function existsByKey(string $key): bool
            {
                foreach ($this->defs as $d) {
                    if ($d->getKey() === $key) {
                        return true;
                    }
                }

                return false;
            }

            public function maxPosition(): int
            {
                $max = -1;
                foreach ($this->defs as $d) {
                    $max = max($max, $d->getPosition());
                }

                return $max;
            }

            public function save(AchievementDefinition $definition): void
            {
                $this->defs[] = $definition;
            }

            public function flush(): void
            {
            }
        };
    }
}
