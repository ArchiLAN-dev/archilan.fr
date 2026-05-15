<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\PlayerProfileQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlayerProfileController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PlayerProfileQuery $playerProfileQuery,
    ) {
    }

    #[Route('/api/v1/players/{slug}', methods: ['GET'])]
    public function profile(string $slug): JsonResponse
    {
        $profile = $this->playerProfileQuery->execute($slug);
        if (null === $profile) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $profile]);
    }
}
