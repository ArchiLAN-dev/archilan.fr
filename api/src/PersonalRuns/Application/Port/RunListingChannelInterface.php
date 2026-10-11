<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Port;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;

/**
 * Where the run listings are told outside the site (story 43.27): a Discord channel, one message per listing, or
 * nowhere.
 */
interface RunListingChannelInterface
{
    /** Whether the listings go anywhere at all. */
    public function isEnabled(): bool;

    /**
     * Posts a new message, mentioning the listings role when asked and one is configured.
     *
     * @return array{messageId: string, mentioned: bool}
     *
     * @throws RunListingDeliveryException
     */
    public function post(RunListingAnnouncement $announcement, bool $mention): array;

    /**
     * Rewrites a message already posted. An edit never notifies.
     *
     * @return bool false when the message is gone (deleted on Discord)
     *
     * @throws RunListingDeliveryException
     */
    public function edit(string $messageId, RunListingAnnouncement $announcement): bool;

    /**
     * Deletes a message; one already gone is fine.
     *
     * @throws RunListingDeliveryException
     */
    public function delete(string $messageId): void;
}
