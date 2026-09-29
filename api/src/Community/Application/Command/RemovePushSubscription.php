<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Domain\Repository\PushSubscriptionRepositoryInterface;

/**
 * A member turns browser pushes off for the device they are on (story 40.2). Only their own: an endpoint
 * registered by someone else is left alone, and the answer does not say whether it exists.
 */
final readonly class RemovePushSubscription
{
    public function __construct(
        private PushSubscriptionRepositoryInterface $subscriptions,
    ) {
    }

    public function remove(string $userId, string $endpoint): void
    {
        $subscription = $this->subscriptions->findByEndpoint($endpoint);
        if (null === $subscription || $subscription->getUserId() !== $userId) {
            return;
        }

        $this->subscriptions->remove($subscription);
        $this->subscriptions->flush();
    }
}
