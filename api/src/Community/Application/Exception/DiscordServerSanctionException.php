<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * The bot could not apply a sanction on the Discord server (story 39.5). Transient failures (rate limit,
 * Discord down, network) are worth a retry; the others (missing permission, the bot's role below the member's)
 * are not.
 */
final class DiscordServerSanctionException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, public readonly bool $transient = false)
    {
        parent::__construct($message, 0, $previous);
    }
}
