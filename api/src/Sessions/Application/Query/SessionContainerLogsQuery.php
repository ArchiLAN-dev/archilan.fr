<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Exception\SessionLogsUnavailableException;
use App\Sessions\Application\Port\SessionContainerLogsInterface;
use App\Sessions\Application\Support\SessionContainerLogs;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * The logs of one container of a session, for the admin (story 17.33).
 */
final readonly class SessionContainerLogsQuery
{
    private const int TAIL = 500;

    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionContainerLogsInterface $logs,
    ) {
    }

    /**
     * Null when the session does not exist.
     *
     * @param 'ap'|'bridge'|'gen' $container
     *
     * @throws SessionLogsUnavailableException
     */
    public function forSession(string $sessionId, string $container): ?SessionContainerLogs
    {
        if (!$this->sessions->findById($sessionId) instanceof Session) {
            return null;
        }

        return $this->logs->read($sessionId, $container, self::TAIL);
    }
}
