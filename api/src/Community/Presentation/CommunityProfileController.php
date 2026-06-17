<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityProfileView;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityProfileController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityProfileView $profileView,
    ) {
    }

    #[Route('/api/v1/community/profiles/{slug}', name: 'api_community_profile', methods: ['GET'])]
    public function profile(string $slug): JsonResponse
    {
        $profile = $this->profileView->forSlug($slug);
        if (null === $profile) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $profile]);
    }
}
