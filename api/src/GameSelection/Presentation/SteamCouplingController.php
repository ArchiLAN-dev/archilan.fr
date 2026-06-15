<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\SteamLibraryCouplingQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SteamCouplingController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SteamLibraryCouplingQuery $coupling,
    ) {
    }

    #[Route('/api/v1/games/steam-coupling', name: 'api_game_selection_steam_coupling', methods: ['POST'])]
    public function couple(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $rawProfile = is_array($payload) ? ($payload['steamProfile'] ?? null) : null;
        $steamProfile = is_string($rawProfile) ? trim($rawProfile) : '';

        if ('' === $steamProfile) {
            return $this->apiAccessGuard->errorResponse('steam_invalid_input', 'Profil Steam requis.', 422);
        }

        $result = $this->coupling->couple($steamProfile);

        return match ($result['outcome']) {
            'invalid_input' => $this->apiAccessGuard->errorResponse('steam_invalid_input', 'Profil Steam non reconnu.', 422),
            'steam_error' => $this->apiAccessGuard->errorResponse('steam_unavailable', 'Steam est indisponible. Réessaie plus tard.', 502),
            default => new JsonResponse([
                'data' => [
                    'matchedGames' => $result['matchedGames'],
                    'ownedCount' => $result['ownedCount'],
                    'matchedCount' => $result['matchedCount'],
                ],
                'meta' => ['outcome' => $result['outcome']],
            ]),
        };
    }
}
