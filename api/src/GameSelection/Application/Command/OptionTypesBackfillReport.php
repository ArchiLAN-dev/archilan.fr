<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Outcome of an option-types backfill run (story 9.53).
 *
 * Kept apart from {@see GameBackfillReport}, which the platforms and steam-app-id backfills share:
 * only this one can re-introspect, and adding its two counters there would hand the other two a
 * pair of zeroes that mean nothing.
 */
final readonly class OptionTypesBackfillReport
{
    public function __construct(
        /** Games with an apworld that the run looked at. */
        public int $processed,
        /** Games whose stored option types actually changed hands. */
        public int $updated,
        /** Apworlds the runner introspected again (0 unless --reintrospect). */
        public int $reintrospected = 0,
        /** Apworlds the runner refused to introspect; their previous answer is still in place. */
        public int $reintrospectionFailed = 0,
    ) {
    }
}
