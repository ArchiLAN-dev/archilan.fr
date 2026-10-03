<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\Enum\WeeklyQuest;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The quests of the week as the wallet page shows them (story 41.6): what each pays, whether it is done, whether it
 * is already paid (the hourly run pays it within the hour), and when the quests renew.
 */
final readonly class MyWeeklyQuests
{
    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{week: string, renewsAt: string, quests: list<array{key: string, label: string, reward: int, done: bool, paid: bool}>}
     */
    public function of(string $userId): array
    {
        $week = QuestWeek::containing($this->clock->now());
        $completers = $this->quests->completers($week);
        $paid = $this->quests->rewardedQuests($userId, $week);

        $quests = [];
        foreach (WeeklyQuest::cases() as $quest) {
            $isPaid = \in_array($quest->value, $paid, true);
            $quests[] = [
                'key' => $quest->value,
                'label' => $quest->label(),
                'reward' => $quest->reward(),
                'done' => $isPaid || \in_array($userId, $completers[$quest->value] ?? [], true),
                'paid' => $isPaid,
            ];
        }

        return ['week' => $week->key, 'renewsAt' => $week->end->format(\DATE_ATOM), 'quests' => $quests];
    }
}
