<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * Thrown out of the forum handler on a transient failure so Messenger retries the job (story 39.1).
 */
final class ModerationForumTemporarilyUnavailableException extends \RuntimeException
{
}
