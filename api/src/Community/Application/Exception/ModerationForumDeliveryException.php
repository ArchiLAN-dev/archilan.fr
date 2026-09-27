<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * The staff forum could not take a message (story 39.1). Never fatal: the sanction it reports is already
 * committed. Transient failures (rate limit, Discord down, network) are worth a retry; the others (missing
 * permission, unknown forum) are not.
 */
final class ModerationForumDeliveryException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, public readonly bool $transient = false)
    {
        parent::__construct($message, 0, $previous);
    }
}
