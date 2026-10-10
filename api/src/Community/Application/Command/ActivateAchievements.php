<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Turns on achievements seeded inactive (story 43.18), right before a recompute that notifies their grants: the
 * hourly recompute never notifies, so an active definition would otherwise be granted in silence.
 */
final readonly class ActivateAchievements
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $definitions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $keys
     *
     * @return int how many definitions were turned on
     */
    public function activate(array $keys): int
    {
        $wanted = array_flip($keys);
        $now = $this->clock->now();
        $activated = 0;
        foreach ($this->definitions->all() as $definition) {
            if (isset($wanted[$definition->getKey()]) && !$definition->isActive()) {
                $definition->activate($now);
                ++$activated;
            }
        }
        if ($activated > 0) {
            $this->definitions->flush();
        }

        return $activated;
    }
}
