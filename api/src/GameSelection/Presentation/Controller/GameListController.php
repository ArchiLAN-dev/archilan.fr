<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Service\GameListOutcome;
use App\GameSelection\Application\Service\UserGameLists;
use App\GameSelection\Domain\Enum\GameListKind;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The lists a player keeps on ArchiLAN - today "I have this game" (story 28.13).
 *
 * The list kind travels in the path rather than in a body, because it is what is being addressed,
 * not a property of the request. An unknown kind is a 404: answering with an empty list would let a
 * typo look like an empty shelf.
 *
 * Authentication is required on all three routes - unlike the Steam coupling, which works
 * anonymously through localStorage, these lists belong to an account because that is what makes
 * them survive a browser change.
 */
final readonly class GameListController
{
    use RequiresAuthTrait;

    public function __construct(
        private UserGameLists $lists,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/me/game-lists/{kind}', name: 'api_game_lists_list', methods: ['GET'])]
    public function list(Request $request, string $kind): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $listKind = GameListKind::tryFrom($kind);
        if (!$listKind instanceof GameListKind) {
            return $this->unknownKind();
        }

        return new JsonResponse(['data' => $this->lists->gameIds($user->getId(), $listKind)]);
    }

    #[Route('/api/v1/me/game-lists/{kind}/{gameId}', name: 'api_game_lists_add', methods: ['PUT'])]
    public function add(Request $request, string $kind, string $gameId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $listKind = GameListKind::tryFrom($kind);
        if (!$listKind instanceof GameListKind) {
            return $this->unknownKind();
        }

        if (GameListOutcome::GameNotFound === $this->lists->add($user->getId(), $gameId, $listKind)) {
            return $this->apiAccessGuard->errorResponse('game_not_found', 'Jeu introuvable.', 404);
        }

        return new JsonResponse(['data' => ['gameId' => $gameId, 'kind' => $listKind->value, 'inList' => true]]);
    }

    #[Route('/api/v1/me/game-lists/{kind}/{gameId}', name: 'api_game_lists_remove', methods: ['DELETE'])]
    public function remove(Request $request, string $kind, string $gameId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $listKind = GameListKind::tryFrom($kind);
        if (!$listKind instanceof GameListKind) {
            return $this->unknownKind();
        }

        $this->lists->remove($user->getId(), $gameId, $listKind);

        return new JsonResponse(['data' => ['gameId' => $gameId, 'kind' => $listKind->value, 'inList' => false]]);
    }

    private function unknownKind(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('game_list_not_found', 'Liste inconnue.', 404);
    }
}
