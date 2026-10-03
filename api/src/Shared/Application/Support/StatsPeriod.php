<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * The period of the admin statistics page (story 42.1): 4 or 12 weeks cut by week, or 12 months cut by month,
 * always ending with the bucket in progress, plus the period of the same length just before it for the
 * comparison. Buckets start on Monday 00:00 UTC, or on the 1st of the month UTC.
 *
 * Every section reads its rows between `start` (inclusive) and `end` (exclusive), groups them with
 * `date_trunc(granularity, ... AT TIME ZONE 'UTC')` and hands the counts back to {@see series()}, which fills
 * the empty buckets with zeros so every chart keeps an even time axis.
 */
final readonly class StatsPeriod
{
    public const string DEFAULT_CODE = '12s';

    /** @var array<string, array{0: 'week'|'month', 1: int}> */
    private const array CODES = [
        '4s' => ['week', 4],
        '12s' => ['week', 12],
        '12m' => ['month', 12],
    ];

    /**
     * @param 'week'|'month'           $granularity
     * @param list<\DateTimeImmutable> $buckets
     */
    private function __construct(
        public string $code,
        public string $granularity,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public \DateTimeImmutable $previousStart,
        private array $buckets,
    ) {
    }

    /** An unknown or missing code falls back to 12 weeks. */
    public static function fromCode(?string $code, \DateTimeImmutable $now): self
    {
        $code = null !== $code && isset(self::CODES[$code]) ? $code : self::DEFAULT_CODE;
        [$granularity, $count] = self::CODES[$code];
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        $current = 'week' === $granularity
            ? $utc->modify('monday this week')->setTime(0, 0)
            : $utc->modify('first day of this month')->setTime(0, 0);
        $step = 'week' === $granularity ? '1 week' : '1 month';

        $start = $current->modify(sprintf('-%d %s', $count - 1, 'week' === $granularity ? 'weeks' : 'months'));
        $buckets = [];
        for ($bucket = $start; $bucket <= $current; $bucket = $bucket->modify('+'.$step)) {
            $buckets[] = $bucket;
        }

        return new self(
            $code,
            $granularity,
            $start,
            $current->modify('+'.$step),
            $start->modify(sprintf('-%d %s', $count, 'week' === $granularity ? 'weeks' : 'months')),
            $buckets,
        );
    }

    /**
     * The values per bucket, keyed by the bucket's start date (`Y-m-d`), with zeros where nothing happened.
     *
     * @param array<string, int> $values
     *
     * @return list<array{start: string, value: int, current: bool}>
     */
    public function series(array $values): array
    {
        $last = \count($this->buckets) - 1;
        $series = [];
        foreach ($this->buckets as $index => $bucket) {
            $key = $bucket->format('Y-m-d');
            $series[] = ['start' => $key, 'value' => $values[$key] ?? 0, 'current' => $index === $last];
        }

        return $series;
    }

    /**
     * What the front needs to label the page.
     *
     * @return array{code: string, granularity: string, start: string, end: string, previousStart: string}
     */
    public function describe(): array
    {
        return [
            'code' => $this->code,
            'granularity' => $this->granularity,
            'start' => $this->start->format(\DATE_ATOM),
            'end' => $this->end->format(\DATE_ATOM),
            'previousStart' => $this->previousStart->format(\DATE_ATOM),
        ];
    }
}
