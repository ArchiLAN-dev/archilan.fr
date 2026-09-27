<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * The bot could not send a member a direct message (story 39.3). Transient failures (rate limit, Discord
 * down, network) are worth a retry; the others (closed DMs, member gone from the server) are not.
 */
final class MemberDirectMessageException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, public readonly bool $transient = false)
    {
        parent::__construct($message, 0, $previous);
    }
}
