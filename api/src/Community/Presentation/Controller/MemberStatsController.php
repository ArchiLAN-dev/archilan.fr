<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\MemberStatsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 30.55: the public stats page of a member, everything the site tracks about their play.
 */
final readonly class MemberStatsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MemberStatsQuery $stats,
    ) {
    }

    #[Route('/api/v1/community/profiles/{slug}/stats', name: 'api_community_profile_stats', methods: ['GET'])]
    public function stats(string $slug): JsonResponse
    {
        $stats = $this->stats->forSlug($slug);
        if (null === $stats) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $stats]);
    }
}
