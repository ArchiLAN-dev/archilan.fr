<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\Enum\WelcomeStep;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;

/**
 * The first steps on « Mon portefeuille » (story 41.25): each with what it pays, done or not, paid or about to be
 * (the run passes every 5 minutes). Nothing for an account created before the welcome quests, nor once every step
 * is paid.
 */
final readonly class MyWelcomeQuests
{
    public function __construct(
        private WelcomeQuestsQueryInterface $welcome,
        private QuestRepositoryInterface $settings,
    ) {
    }

    /**
     * @return array{steps: list<array{key: string, label: string, description: string, reward: int, done: bool, paid: bool}>, total: int}|null
     */
    public function of(string $userId): ?array
    {
        $since = $this->settings->welcomeQuestsSince();
        $joinedAt = $this->welcome->joinedAt($userId);
        if (null === $joinedAt || (null !== $since && $joinedAt < $since)) {
            return null;
        }

        $paid = $this->welcome->paidTo($userId);
        if (\count($paid) >= \count(WelcomeStep::cases())) {
            return null;
        }
        $done = $this->welcome->doneBy($userId);

        $steps = [];
        foreach (WelcomeStep::cases() as $step) {
            $isPaid = \in_array($step, $paid, true);
            $steps[] = [
                'key' => $step->value,
                'label' => $step->label(),
                'description' => $step->description(),
                'reward' => $step->reward(),
                'done' => $isPaid || \in_array($step, $done, true),
                'paid' => $isPaid,
            ];
        }

        return ['steps' => $steps, 'total' => array_sum(array_column($steps, 'reward'))];
    }
}
