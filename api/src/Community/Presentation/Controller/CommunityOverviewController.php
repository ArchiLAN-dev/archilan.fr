<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\CommunityOverviewQuery;
use App\Identity\Domain\Entity\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The /communaute hub's own read (story 30.38): member count, who is playing, latest achievements.
 */
final readonly class CommunityOverviewController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityOverviewQuery $overview,
    ) {
    }

    #[Route('/api/v1/community/overview', name: 'api_community_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        return new JsonResponse(
            ['data' => $this->overview->forViewer($viewerId)],
            // `private`, never `public`: both lists are filtered per viewer, so a shared cache would hand
            // one member's visible payload to the next requester. 30s is enough to absorb a page reload
            // without making "en jeu maintenant" stale.
            headers: ['Cache-Control' => 'private, max-age=30'],
        );
    }
}
