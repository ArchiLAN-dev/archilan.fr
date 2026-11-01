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

    /** « 2 goals sur Hollow Knight », « 50 checks », « 6 h de jeu » - an objective in words. */
    public static function describe(QuestObjective $objective, ?string $scopeName): string
    {
        $text = $objective->metric->describe($objective->target);
        if (null === $objective->scope) {
            return $text;
        }

        return match ($objective->scope) {
            QuestObjective::SCOPE_GAME => sprintf('%s sur %s', $text, $scopeName ?? 'une cible retirée'),
            // Story 41.66: « 3 goals sur un jeu Super Nintendo ou Nintendo 64 ».
            QuestObjective::SCOPE_PLATFORM, QuestObjective::SCOPE_RECENT, QuestObjective::SCOPE_DRAWN => sprintf('%s sur un %s', $text, $objective->selfName() ?? 'jeu retiré'),
            default => sprintf('%s à %s', $text, $scopeName ?? 'une cible retirée'),
        };
    }
}
