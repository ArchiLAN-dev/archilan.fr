<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * Thrown out of the sanction job on a transient server failure so Messenger retries it (story 39.5).
 */
final class DiscordServerTemporarilyUnavailableException extends \RuntimeException
{
}
