<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Adapter;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;

/** No Discord channel or no bot configured (story 43.27): the listings are only on the site. */
final readonly class DisabledRunListingChannel implements RunListingChannelInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function post(RunListingAnnouncement $announcement, bool $mention): array
    {
        throw new RunListingDeliveryException('No Discord channel configured for the run listings.');
    }

    public function edit(string $messageId, RunListingAnnouncement $announcement): bool
    {
        throw new RunListingDeliveryException('No Discord channel configured for the run listings.');
    }

    public function delete(string $messageId): void
    {
        throw new RunListingDeliveryException('No Discord channel configured for the run listings.');
    }
}
