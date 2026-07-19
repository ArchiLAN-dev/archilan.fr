<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * A named superlative awarded to one slot (e.g. "most generous"). Keyed by slot
 * name; the build handler maps it to a slot id when persisting the projection.
 *
 * `value` carries the metric behind the award (a count for count-based awards,
 * an ISO-8601 timestamp for time-based ones) for display alongside the label.
 */
final readonly class RecapSuperlative
{
    public function __construct(
        public string $key,
        public string $label,
        public string $slotName,
        public int|string $value,
    ) {
    }
}
