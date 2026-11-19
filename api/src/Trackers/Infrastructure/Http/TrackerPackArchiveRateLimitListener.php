<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Story 54.13: the zip of a tracker pack is public and up to 50 MB; a flood of downloads from one address would
 * hold the PHP workers. Counted by client address, generous enough for a LAN behind a single one.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class TrackerPackArchiveRateLimitListener
{
    public const string ROUTE = 'api_tracker_pack_archive';

    public function __construct(
        #[Target('tracker_pack_archive.limiter')] private RateLimiterFactoryInterface $trackerPackArchiveLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $limit = $this->trackerPackArchiveLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => 'too_many_requests',
                'message' => 'Trop de téléchargements de packs. Réessaie dans quelques minutes.',
                'details' => [],
            ],
        ], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]));
    }
}
