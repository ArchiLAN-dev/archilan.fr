<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Handler;

use App\WeeklyRuns\Application\Command\ResolveWeeklyDuels;
use App\WeeklyRuns\Application\Message\ResolveWeeklyDuelsMessage;
use App\WeeklyRuns\Application\Query\WeeklyDuelContextQueryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Settles the duels of the weekly runs that ended at least GRACE ago (story 43.18). Not tied to the weekly stop: a
 * goal reached just before the end but received a little later still counts, and a run whose duels failed to settle
 * is picked up again on the next pass. One run failing never holds back the others.
 */
#[AsMessageHandler]
final readonly class ResolveWeeklyDuelsHandler
{
    public const string GRACE = '-15 minutes';

    public function __construct(
        private WeeklyDuelContextQueryInterface $context,
        private ResolveWeeklyDuels $resolve,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResolveWeeklyDuelsMessage $message): void
    {
        foreach ($this->context->finishedRunsWithOpenDuels($this->clock->now()->modify(self::GRACE)) as $weeklyRunId) {
            try {
                $this->resolve->forRun($weeklyRunId);
            } catch (\Throwable $e) {
                $this->logger->error('weekly_duel.resolve_failed', ['weeklyRunId' => $weeklyRunId, 'error' => $e->getMessage()]);
            }
        }
    }
}
