<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;

/**
 * Story 41.38: what a player of a private run sees of its bronze - their balance, what the run hands out and what
 * it sells, and their history in it. Their own pelles only: nobody else's balance is shown.
 */
final readonly class MyRunBronze
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionConfigResolver $configResolver,
        private RunBronzeQueryInterface $bronze,
    ) {
    }

    /**
     * @return array{balance: int, ended: bool, start: int, perCheck: int, perGoal: int, hints: bool, itemHintPrice: int, locationHintPrice: int, bounties: bool, history: list<array{reason: string, label: string, amount: int, count: int, at: string}>}|null
     */
    public function of(string $userId, string $runId): ?array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return null;
        }

        $server = $this->configResolver->resolve(SessionType::Private, $run->getId())->server;
        $mine = $this->bronze->ofMember($userId, $run->getId());

        return [
            'balance' => $mine['balance'],
            'ended' => \in_array($run->getStatus(), [Run::STATUS_COMPLETED, Run::STATUS_CANCELLED], true),
            'start' => $server->bronzeStart,
            'perCheck' => $server->bronzePerCheck,
            'perGoal' => $server->bronzePerGoal,
            'hints' => $server->pelleHints,
            'itemHintPrice' => $server->pelleItemHintPrice,
            'locationHintPrice' => $server->pelleLocationHintPrice,
            'bounties' => $server->pelleBounties,
            'history' => $mine['history'],
        ];
    }
}
