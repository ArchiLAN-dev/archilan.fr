<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Application\Message\ExpireRunListingsMessage;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A listing nobody joined for 14 days is taken down (story 43.17): the run goes back to invitations only, and its
 * owner hears of it in the bell once saved.
 */
#[AsMessageHandler]
final readonly class ExpireRunListingsHandler
{
    public const string NOTIFICATION_TYPE = 'run_listing_expired';

    public function __construct(
        private RunRepositoryInterface $runs,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireRunListingsMessage $message): void
    {
        $now = $this->clock->now();
        $expired = array_values(array_filter($this->runs->findListed(), static fn (Run $run): bool => $run->isListingExpired($now)));
        if ([] === $expired) {
            return;
        }

        foreach ($expired as $run) {
            $run->expireListing($now);
        }
        $this->runs->flush();

        foreach ($expired as $run) {
            $this->notifier->notify($run->getOwnerId(), self::NOTIFICATION_TYPE, [
                'runId' => $run->getId(),
                'runTitle' => $run->getTitle(),
            ]);
        }
    }
}
