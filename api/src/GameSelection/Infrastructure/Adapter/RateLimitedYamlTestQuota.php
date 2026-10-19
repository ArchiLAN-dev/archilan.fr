<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Adapter;

use App\GameSelection\Application\Port\YamlTestQuotaInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Story 9.57: the YAML test allowance on Symfony's rate limiter (`game_yaml_test`, a sliding window of
 * 5 tests per 10 minutes, see config/packages/rate_limiter.yaml). The limiter counts under a lock, so two
 * simultaneous requests cannot both take the last test, and the sliding window gives no burst at the turn
 * of a fixed window.
 */
final readonly class RateLimitedYamlTestQuota implements YamlTestQuotaInterface
{
    public function __construct(private RateLimiterFactoryInterface $gameYamlTestLimiter)
    {
    }

    public function consume(string $userId): bool
    {
        return $this->gameYamlTestLimiter->create('user-'.$userId)->consume()->isAccepted();
    }
}
