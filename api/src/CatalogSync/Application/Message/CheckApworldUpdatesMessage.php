<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Message;

/**
 * Scheduled marker (every night): check every GitHub-tracked apworld for a newer release (story 38.5).
 */
final readonly class CheckApworldUpdatesMessage
{
}
