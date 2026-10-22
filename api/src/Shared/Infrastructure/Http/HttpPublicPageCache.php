<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Port\PublicPageCacheInterface;
use App\Shared\Application\Port\PublicPageCacheOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Story 34.10: the frontend's `POST /api/revalidate`, reached on the Docker network (`FRONTEND_INTERNAL_URL`,
 * http://archilan-frontend:3000 in prod), never through the public domain. Without the URL or the secret it does
 * nothing, which is how local dev and CI run.
 */
final readonly class HttpPublicPageCache implements PublicPageCacheInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $frontendInternalUrl,
        private string $revalidateSecret,
    ) {
    }

    public function invalidate(array $tags): PublicPageCacheOutcome
    {
        if ('' === trim($this->frontendInternalUrl) || '' === $this->revalidateSecret) {
            $this->logger->info('frontend.revalidate.disabled', ['tags' => $tags]);

            return PublicPageCacheOutcome::Disabled;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->frontendInternalUrl, '/').'/api/revalidate', [
                'headers' => ['Authorization' => 'Bearer '.$this->revalidateSecret],
                'json' => ['tags' => $tags],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('frontend.revalidate.unreachable', ['tags' => $tags, 'error' => $e->getMessage()]);

            return PublicPageCacheOutcome::Unavailable;
        }

        return match (true) {
            200 === $status => PublicPageCacheOutcome::Revalidated,
            400 === $status, 401 === $status => PublicPageCacheOutcome::Rejected,
            default => PublicPageCacheOutcome::Unavailable,
        };
    }
}
