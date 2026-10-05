<?php

declare(strict_types=1);

namespace App\Wallet\Domain\ValueObject;

/**
 * The week of the quests (story 41.6): Monday 00:00 to Monday 00:00, Paris time - the week the members live, not
 * the UTC buckets of the statistics. `key` names it in the ledger (`2026-W40`).
 */
final readonly class QuestWeek
{
    private const string ZONE = 'Europe/Paris';

    private function __construct(
        public string $key,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function containing(\DateTimeImmutable $moment): self
    {
        $local = $moment->setTimezone(new \DateTimeZone(self::ZONE));
        $start = $local->modify('monday this week')->setTime(0, 0);

        return new self($start->format('o-\WW'), $start, $start->modify('+1 week'));
    }

    /** Story 41.15: the week a ledger or admin key names (`2026-W41`); null when the key is not one. */
    public static function fromKey(string $key): ?self
    {
        if (1 !== preg_match('/^(\d{4})-W(\d{2})$/', $key, $parts)) {
            return null;
        }
        // The base instant is irrelevant: setISODate replaces the date, setTime the time.
        $monday = new \DateTimeImmutable('@0')->setTimezone(new \DateTimeZone(self::ZONE))->setISODate((int) $parts[1], (int) $parts[2])->setTime(0, 0);
        $week = self::containing($monday);

        return $week->key === $key ? $week : null;
    }

    public function next(): self
    {
        return self::containing($this->end->modify('+1 day'));
    }

    public function previous(): self
    {
        return self::containing($this->start->modify('-1 day'));
    }
}
