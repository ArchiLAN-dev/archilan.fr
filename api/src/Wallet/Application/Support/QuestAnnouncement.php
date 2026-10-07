<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Wallet\Domain\ValueObject\QuestObjective;

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

    /** « 2 goals sur Hollow Knight », « 50 checks » - an objective in words. */
    public static function describe(QuestObjective $objective, ?string $scopeName): string
    {
        $text = sprintf('%d %s', $objective->target, $objective->metric->unitFor($objective->target));
        if (null === $objective->scope) {
            return $text;
        }

        return sprintf(QuestObjective::SCOPE_GAME === $objective->scope ? '%s sur %s' : '%s à %s', $text, $scopeName ?? 'une cible retirée');
    }
}
