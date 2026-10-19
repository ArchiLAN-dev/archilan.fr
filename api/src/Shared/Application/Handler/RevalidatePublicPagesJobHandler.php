<?php

declare(strict_types=1);

namespace App\Shared\Application\Handler;

use App\Shared\Application\Exception\PublicPageCacheUnavailableException;
use App\Shared\Application\Message\RevalidatePublicPagesJob;
use App\Shared\Application\Port\PublicPageCacheInterface;
use App\Shared\Application\Port\PublicPageCacheOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 34.10: asks the frontend to revalidate the tags of a write. A frontend that does not answer is retried
 * (the async transport's policy, then async_failed); a refusal is a configuration fault, logged and dropped.
 * Either way the pages still refresh within five minutes on their own.
 */
#[AsMessageHandler]
final readonly class RevalidatePublicPagesJobHandler
{
    public function __construct(
        private PublicPageCacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RevalidatePublicPagesJob $job): void
    {
        $outcome = $this->cache->invalidate($job->tags);

        if (PublicPageCacheOutcome::Unavailable === $outcome) {
            throw new PublicPageCacheUnavailableException($job->tags);
        }
        if (PublicPageCacheOutcome::Rejected === $outcome) {
            $this->logger->warning('frontend.revalidate.rejected', ['tags' => $job->tags]);
        }
    }
}
