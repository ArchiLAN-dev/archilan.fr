<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\FollowGame;
use App\GameSelection\Application\Command\FollowGameOutcome;
use App\GameSelection\Application\Query\GameFollowQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A member follows a game to be told of its new apworlds (story 38.22).
 */
final readonly class GameFollowController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private GameFollowQuery $followQuery,
        private FollowGame $followGame,
    ) {
    }

    #[Route('/api/v1/games/{gameId}/follow', name: 'api_games_follow_state', methods: ['GET'])]
    public function state(Request $request, string $gameId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $following = $this->followQuery->isFollowing($user->getId(), $gameId);
        if (null === $following) {
            return $this->gameNotFound();
        }

        return new JsonResponse(['data' => ['following' => $following]]);
    }

    #[Route('/api/v1/games/{gameId}/follow', name: 'api_games_follow', methods: ['PUT'])]
    public function follow(Request $request, string $gameId): JsonResponse
    {
        return $this->set($request, $gameId, true);
    }

    #[Route('/api/v1/games/{gameId}/follow', name: 'api_games_unfollow', methods: ['DELETE'])]
    public function unfollow(Request $request, string $gameId): JsonResponse
    {
        return $this->set($request, $gameId, false);
    }

    private function set(Request $request, string $gameId, bool $follow): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return match ($this->followGame->set($user->getId(), $gameId, $follow)) {
            FollowGameOutcome::GameNotFound => $this->gameNotFound(),
            FollowGameOutcome::Following => new JsonResponse(['data' => ['following' => true]]),
            FollowGameOutcome::NotFollowing => new JsonResponse(['data' => ['following' => false]]),
        };
    }

    private function gameNotFound(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
    }
}
