<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Adapter;

use App\GameSelection\Application\Port\TutorialImageQuotaInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Story 50.3: the tutorial image allowance on Symfony's rate limiter (`tutorial_image_upload`). */
final readonly class RateLimitedTutorialImageQuota implements TutorialImageQuotaInterface
{
    public function __construct(private RateLimiterFactoryInterface $tutorialImageUploadLimiter)
    {
    }

    public function consume(string $userId): bool
    {
        return $this->tutorialImageUploadLimiter->create('user-'.$userId)->consume()->isAccepted();
    }
}
