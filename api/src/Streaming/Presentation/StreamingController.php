<?php

declare(strict_types=1);

namespace App\Streaming\Presentation;

use App\Streaming\Application\TwitchStatusChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class StreamingController
{
    public function __construct(
        private TwitchStatusChecker $checker,
    ) {
    }

    #[Route('/api/v1/live/status', name: 'api_live_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $status = $this->checker->check();

        return new JsonResponse([
            'data' => [
                'live' => $status->live,
                'viewerCount' => $status->viewerCount,
            ],
            'meta' => [],
        ]);
    }
}
