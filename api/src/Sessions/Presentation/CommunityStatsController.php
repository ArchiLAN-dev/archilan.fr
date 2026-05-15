<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\CommunityStatsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityStatsController
{
    public function __construct(private CommunityStatsQuery $communityStatsQuery)
    {
    }

    #[Route('/api/v1/community/stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return new JsonResponse(
            ['data' => $this->communityStatsQuery->execute()],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
