<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Adapter;

/**
 * A refused or failed call of the bot to the Discord REST API (stories 39.1 and 39.3), mapped by each adapter
 * onto its port's exception. Transient: rate limit, Discord down, network.
 */
final class DiscordRestFailure extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, public readonly bool $transient = false)
    {
        parent::__construct($message, 0, $previous);
    }
}
