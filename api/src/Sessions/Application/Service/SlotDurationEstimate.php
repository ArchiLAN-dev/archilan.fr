<?php

declare(strict_types=1);

namespace App\Sessions\Application\Service;

use App\Sessions\Application\Query\GamePaceQueryInterface;
use App\Sessions\Application\Support\PaceSource;
use App\Sessions\Application\Support\SlotDuration;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Story 56.2: how long a slot should take, on the scale of our past games.
 *
 * - A game's pace is the median checks per hour of its slots played to their goal, from three of them; below that,
 *   the admin's estimate; otherwise unknown.
 * - A player's factor is the mean ratio of their pace to the game's, pulled toward 1 by `n / (n + 3)` and bounded
 *   between 0.5 and 2: a newcomer plays at the game's pace.
 * - A slot's duration is `locations / (pace x factor)`.
 *
 * Everything rests on finished games only, so the computed paces are kept an hour.
 */
final readonly class SlotDurationEstimate
{
    public const int MIN_MEASURED_SLOTS = 3;
    public const int FACTOR_PRIOR = 3;
    public const float MIN_FACTOR = 0.5;
    public const float MAX_FACTOR = 2.0;

    private const string CACHE_KEY = 'game_paces.v1';

    public function __construct(
        private GamePaceQueryInterface $paces,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $userIds who plays the slot (several on a shared slot: their factors are averaged)
     */
    public function forSlot(string $gameId, array $userIds, ?int $locations): SlotDuration
    {
        $model = $this->model();
        [$pace, $source, $slots] = self::paceOf($model, $gameId);

        $factors = array_map(static fn (string $userId): float => $model['factors'][$userId] ?? 1.0, $userIds);
        $factor = [] === $factors ? 1.0 : array_sum($factors) / count($factors);

        $hours = null === $pace || null === $locations || $locations <= 0 ? null : $locations / ($pace * $factor);

        return new SlotDuration($hours, $source, $slots, $pace, $factor);
    }

    public function playerFactor(string $userId): float
    {
        return $this->model()['factors'][$userId] ?? 1.0;
    }

    /**
     * @return array{measured: array<string, array{pace: float, slots: int}>, admin: array<string, int>, factors: array<string, float>}
     */
    private function model(): array
    {
        /* @var array{measured: array<string, array{pace: float, slots: int}>, admin: array<string, int>, factors: array<string, float>} */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->build();
        });
    }

    /**
     * @return array{measured: array<string, array{pace: float, slots: int}>, admin: array<string, int>, factors: array<string, float>}
     */
    private function build(): array
    {
        $samples = $this->paces->samples();

        $byGame = [];
        foreach ($samples as $sample) {
            $byGame[$sample->gameId][] = $sample->checksPerHour;
        }
        $measured = [];
        foreach ($byGame as $gameId => $values) {
            if (count($values) >= self::MIN_MEASURED_SLOTS) {
                $measured[$gameId] = ['pace' => self::median($values), 'slots' => count($values)];
            }
        }

        $model = ['measured' => $measured, 'admin' => $this->paces->adminEstimates(), 'factors' => []];

        $ratios = [];
        foreach ($samples as $sample) {
            [$pace] = self::paceOf($model, $sample->gameId);
            if (null === $pace) {
                continue;
            }
            foreach ($sample->userIds as $userId) {
                $ratios[$userId][] = $sample->checksPerHour / $pace;
            }
        }
        foreach ($ratios as $userId => $values) {
            $n = count($values);
            $mean = array_sum($values) / $n;
            $weighted = 1.0 + ($mean - 1.0) * $n / ($n + self::FACTOR_PRIOR);
            $model['factors'][$userId] = max(self::MIN_FACTOR, min(self::MAX_FACTOR, $weighted));
        }

        return $model;
    }

    /**
     * @param array{measured: array<string, array{pace: float, slots: int}>, admin: array<string, int>, factors: array<string, float>} $model
     *
     * @return array{0: float|null, 1: PaceSource, 2: int}
     */
    private static function paceOf(array $model, string $gameId): array
    {
        if (isset($model['measured'][$gameId])) {
            return [$model['measured'][$gameId]['pace'], PaceSource::Measured, $model['measured'][$gameId]['slots']];
        }
        if (isset($model['admin'][$gameId])) {
            return [(float) $model['admin'][$gameId], PaceSource::Admin, 0];
        }

        return [null, PaceSource::Unknown, 0];
    }

    /** @param non-empty-list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
