<?php

declare(strict_types=1);

namespace App\Trackers\Application\Handler;

use App\Trackers\Application\Command\CheckTrackerPackUpdates;
use App\Trackers\Application\Message\CheckTrackerPackUpdatesMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Story 54.3: runs on the async worker, so the GitHub calls never hold the scheduler. */
#[AsMessageHandler]
final readonly class CheckTrackerPackUpdatesHandler
{
    public function __construct(private CheckTrackerPackUpdates $check)
    {
    }

    public function __invoke(CheckTrackerPackUpdatesMessage $message): void
    {
        $this->check->checkAll();
    }
}
