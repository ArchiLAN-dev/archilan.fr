<?php

declare(strict_types=1);

namespace App\Payments\Application\Message;

/**
 * Hourly backstop sync of the membership form (story 22.7): catches a payment the webhook missed.
 */
final readonly class SyncHelloAssoMembershipFormMessage
{
}
