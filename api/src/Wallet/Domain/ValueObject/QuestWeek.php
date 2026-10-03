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

    public function previous(): self
    {
        return self::containing($this->start->modify('-1 day'));
    }
}
