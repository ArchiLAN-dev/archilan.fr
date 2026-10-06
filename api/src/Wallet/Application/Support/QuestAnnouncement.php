<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * The quests of a week as told outside the site (story 41.24): each quest in words, the chest, the most a member
 * can earn, when they renew, and where to follow them.
 */
final readonly class QuestAnnouncement
{
    /**
     * @param list<array{title: string, objectives: string, reward: int}> $quests
     */
    public function __construct(
        public string $weekKey,
        public array $quests,
        public int $chestReward,
        public \DateTimeImmutable $renewsAt,
        public string $url,
    ) {
    }

    public function maxPelles(): int
    {
        return array_sum(array_column($this->quests, 'reward')) + $this->chestReward;
    }
}
