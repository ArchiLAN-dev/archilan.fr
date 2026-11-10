<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/** Story 52.5: the notice of an opened world's player - only theirs, nobody else's. */
final readonly class PasseurNoticeQuery
{
    public function __construct(private PasseurOpeningQueryInterface $openings)
    {
    }

    public function forAbsent(string $sessionId, string $userId): PasseurNotice
    {
        $openings = $this->openings->unseenBy($sessionId, $userId);

        return new PasseurNotice(
            $openings,
            array_sum(array_map(static fn (PasseurOpeningRow $row): int => $row->share, $openings)),
            [] === $openings ? null : $openings[0]->kind,
        );
    }
}
