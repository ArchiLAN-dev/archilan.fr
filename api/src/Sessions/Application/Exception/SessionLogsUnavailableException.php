<?php

declare(strict_types=1);

namespace App\Sessions\Application\Exception;

/** The orchestrator could not give a container's logs: unreachable, too old, or Docker failed (story 17.33). */
final class SessionLogsUnavailableException extends \RuntimeException
{
}
