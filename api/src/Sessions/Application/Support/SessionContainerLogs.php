<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * The last lines of one container of a session, as the orchestrator read them (story 17.33). found is
 * false when the container does not exist (never launched, or removed since); logs is then empty.
 */
final readonly class SessionContainerLogs
{
    /** The containers an admin can read: the Archipelago server, the bridge, the generation. */
    public const array CONTAINERS = ['ap', 'bridge', 'gen'];

    public function __construct(
        public string $container,
        public bool $found,
        public string $logs,
    ) {
    }
}
