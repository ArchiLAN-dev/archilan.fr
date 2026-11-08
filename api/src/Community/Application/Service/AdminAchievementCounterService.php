<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Query\CounterGameCatalogueQueryInterface;
use App\Community\Application\Query\CounterQuestUsageQueryInterface;
use App\Community\Application\Support\CounterNameSearch;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementCounter;
use App\Community\Domain\Enum\AchievementCounterKind;
use App\Community\Domain\Enum\AchievementCounterMode;
use App\Community\Domain\Repository\AchievementCounterRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 30.57: the admin side of the counters of checks and items - create, edit, delete (not while an achievement
 * or a weekly quest reads it, story 41.72), and the pickers: the games of the catalogue, and the names of one game filtered on what the admin typed.
 *
 * @phpstan-type CounterView array{id: string, label: string, gameId: string, gameName: string|null, kind: string, targets: list<string>, mode: string, withOthersOnly: bool, usedBy: int, usedByQuests: int}
 */
final readonly class AdminAchievementCounterService
{
    public const string DELETED = 'deleted';
    public const string NOT_FOUND = 'not_found';
    public const string IN_USE = 'in_use';

    private const int MAX_GROUPS = 50;

    public function __construct(
        private AchievementCounterRepositoryInterface $counters,
        private AchievementDefinitionRepositoryInterface $definitions,
        private CounterGameCatalogueQueryInterface $catalogue,
        private ClockInterface $clock,
        private CounterQuestUsageQueryInterface $quests,
    ) {
    }

    /**
     * @return array{counters: list<CounterView>, games: list<array{id: string, name: string, archipelagoName: string, introspected: bool}>}
     */
    public function dashboard(): array
    {
        $games = $this->catalogue->games();
        $gameNames = [];
        foreach ($games as $game) {
            $gameNames[$game['id']] = $game['name'];
        }
        $usage = $this->usage();
        $questUsage = $this->quests->quests();

        return [
            'counters' => array_map(fn (AchievementCounter $counter): array => $this->present($counter, $gameNames, $usage, $questUsage), $this->counters->all()),
            'games' => $games,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return CounterView
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $payload): array
    {
        $parts = $this->parts($payload);
        $counter = AchievementCounter::create(bin2hex(random_bytes(16)), $parts['label'], $parts['gameId'], $parts['kind'], $parts['targets'], $parts['mode'], $parts['withOthersOnly'], $this->clock->now());
        $this->counters->save($counter);

        return $this->presentOne($counter);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return CounterView|null
     *
     * @throws \InvalidArgumentException
     */
    public function update(string $id, array $payload): ?array
    {
        $counter = $this->counters->findById($id);
        if (!$counter instanceof AchievementCounter) {
            return null;
        }
        $parts = $this->parts($payload);
        // Story 41.72: a collective quest adds the counter up as a total; another mode would not add up.
        if (AchievementCounterMode::Total !== $parts['mode'] && ($this->quests->collectiveQuests()[$id] ?? 0) > 0) {
            throw new \InvalidArgumentException('Une quête collective lit ce compteur : il reste en mode « Total ».');
        }
        $counter->redefine($parts['label'], $parts['gameId'], $parts['kind'], $parts['targets'], $parts['mode'], $parts['withOthersOnly'], $this->clock->now());
        $this->counters->save($counter);

        return $this->presentOne($counter);
    }

    /**
     * @return self::DELETED|self::NOT_FOUND|self::IN_USE
     */
    public function delete(string $id): string
    {
        $counter = $this->counters->findById($id);
        if (!$counter instanceof AchievementCounter) {
            return self::NOT_FOUND;
        }
        if (($this->usage()[$id] ?? 0) > 0 || ($this->quests->quests()[$id] ?? 0) > 0) {
            return self::IN_USE;
        }
        $this->counters->remove($counter);

        return self::DELETED;
    }

    /**
     * The names of one game for a kind of counter that match what the admin typed (none under two characters), the
     * picked ones left out, and the item groups whose name matches (every group without text). Null when the game
     * cannot carry a counter.
     *
     * @param list<string> $exclude
     *
     * @return array{source: 'introspection'|'feed', total: int, names: list<string>, groups: list<array{name: string, items: list<string>}>, unknown: list<string>}|null
     */
    public function names(string $gameId, string $kind, string $query, array $exclude): ?array
    {
        $kind = AchievementCounterKind::tryFrom($kind);
        if (null === $kind) {
            return null;
        }
        $catalogue = $this->catalogue->names($gameId, $kind);
        if (null === $catalogue) {
            return null;
        }

        $groupNames = array_keys($catalogue['groups']);
        $matching = mb_strlen(CounterNameSearch::normalize($query)) >= CounterNameSearch::MIN_QUERY
            ? CounterNameSearch::search($groupNames, $query, [], self::MAX_GROUPS)
            : array_slice($groupNames, 0, self::MAX_GROUPS);
        $groups = [];
        foreach ($matching as $group) {
            $groups[] = ['name' => $group, 'items' => $catalogue['groups'][$group]];
        }

        return [
            'source' => $catalogue['source'],
            'total' => \count($catalogue['names']),
            'names' => CounterNameSearch::search($catalogue['names'], $query, $exclude),
            'groups' => $groups,
            // The picked names the list no longer has (an apworld updated since): shown as unknown, kept.
            'unknown' => array_values(array_diff($exclude, $catalogue['names'])),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{label: string, gameId: string, kind: AchievementCounterKind, targets: list<string>, mode: AchievementCounterMode, withOthersOnly: bool}
     *
     * @throws \InvalidArgumentException
     */
    private function parts(array $payload): array
    {
        $label = is_string($payload['label'] ?? null) ? $payload['label'] : '';
        $gameId = is_string($payload['gameId'] ?? null) ? trim($payload['gameId']) : '';
        if ('' === $gameId || [] === $this->catalogue->archipelagoNames([$gameId])) {
            throw new \InvalidArgumentException('Ce jeu n\'existe pas ou n\'a pas de nom Archipelago.');
        }
        $kind = is_string($payload['kind'] ?? null) ? AchievementCounterKind::tryFrom($payload['kind']) : null;
        if (null === $kind) {
            throw new \InvalidArgumentException('Nature du compteur invalide.');
        }
        $mode = is_string($payload['mode'] ?? null) ? AchievementCounterMode::tryFrom($payload['mode']) : null;
        if (null === $mode) {
            throw new \InvalidArgumentException('Mode du compteur invalide.');
        }
        $targets = [];
        $raw = $payload['targets'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $target) {
                if (is_string($target)) {
                    $targets[] = $target;
                }
            }
        }

        return [
            'label' => $label,
            'gameId' => $gameId,
            'kind' => $kind,
            'targets' => $targets,
            'mode' => $mode,
            'withOthersOnly' => true === ($payload['withOthersOnly'] ?? false),
        ];
    }

    /**
     * How many achievements (active or not) read each counter.
     *
     * @return array<string, int>
     */
    private function usage(): array
    {
        $usage = [];
        foreach ($this->definitions->all() as $definition) {
            foreach (AchievementMetricCatalog::counterIdsIn($definition->getRule()) as $id) {
                $usage[$id] = ($usage[$id] ?? 0) + 1;
            }
        }

        return $usage;
    }

    /**
     * @return CounterView
     */
    private function presentOne(AchievementCounter $counter): array
    {
        $gameName = null;
        foreach ($this->catalogue->games() as $game) {
            if ($game['id'] === $counter->getGameId()) {
                $gameName = $game['name'];
            }
        }

        return $this->present($counter, null === $gameName ? [] : [$counter->getGameId() => $gameName], $this->usage(), $this->quests->quests());
    }

    /**
     * @param array<string, string> $gameNames
     * @param array<string, int>    $usage      achievements by counter
     * @param array<string, int>    $questUsage quests by counter
     *
     * @return CounterView
     */
    private function present(AchievementCounter $counter, array $gameNames, array $usage, array $questUsage): array
    {
        return [
            'id' => $counter->getId(),
            'label' => $counter->getLabel(),
            'gameId' => $counter->getGameId(),
            'gameName' => $gameNames[$counter->getGameId()] ?? null,
            'kind' => $counter->getKind()->value,
            'targets' => $counter->getTargets(),
            'mode' => $counter->getMode()->value,
            'withOthersOnly' => $counter->isWithOthersOnly(),
            'usedBy' => $usage[$counter->getId()] ?? 0,
            'usedByQuests' => $questUsage[$counter->getId()] ?? 0,
        ];
    }
}
