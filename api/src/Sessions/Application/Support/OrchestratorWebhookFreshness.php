<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use Psr\Clock\ClockInterface;

/**
 * Story 50.5: an orchestrateur webhook counts only close to the moment it was signed. The sender stamps the body right
 * before sending and never retries, so the tolerance only has to cover clock drift between the two hosts.
 */
final readonly class OrchestratorWebhookFreshness
{
    public const int TOLERANCE_SECONDS = 300;

    /** RFC 3339 with any fraction length: Go writes up to nine digits and drops the trailing zeros. */
    private const string RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function accepts(mixed $timestamp): bool
    {
        if (!is_string($timestamp) || 1 !== preg_match(self::RFC3339, $timestamp)) {
            return false;
        }

        try {
            $sentAt = new \DateTimeImmutable($timestamp);
        } catch (\DateMalformedStringException) {
            return false;
        }

        return abs($this->clock->now()->getTimestamp() - $sentAt->getTimestamp()) <= self::TOLERANCE_SECONDS;
    }
}
