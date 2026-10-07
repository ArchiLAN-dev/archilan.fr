<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * A refused or failed call of the bot to the Discord REST API (stories 39.1 and 39.3), mapped by each adapter
 * onto its port's exception. Transient: rate limit, Discord down, network.
 */
final class DiscordRestFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly bool $transient = false,
        public readonly int $status = 0,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
