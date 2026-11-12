<?php

declare(strict_types=1);

namespace App\Streaming\Application\Command;

/** Story 47.6: what the site made of a régie agent report. */
final readonly class LocalStreamsReport
{
    public function __construct(
        /** Streams of this event's registrants, now live. */
        public int $live,
        /** Paths the agent listed that belong to no registrant of the event. */
        public int $ignored,
        /**
         * v0.66.1: paths the agent must cut on MediaMTX: a registrant no longer allowed to publish (cancelled,
         * broadcast refused, banned, window over), or a session started with a key replaced since.
         *
         * @var list<string>
         */
        public array $kick = [],
    ) {
    }
}
