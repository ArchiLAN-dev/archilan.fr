<?php

declare(strict_types=1);

namespace App\Community\Application\Exception;

/**
 * Thrown out of the staff reply delivery on a transient direct message failure so Messenger retries the job
 * (story 39.3).
 */
final class MemberDirectMessageTemporarilyUnavailableException extends \RuntimeException
{
}
