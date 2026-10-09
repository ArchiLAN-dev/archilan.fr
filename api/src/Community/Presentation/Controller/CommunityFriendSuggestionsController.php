<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\DismissFriendSuggestion;
use App\Community\Application\Command\DismissFriendSuggestionOutcome;
use App\Community\Application\Query\FriendSuggestionsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Tu as joué avec » (story 43.2): friend suggestions from the games played together, and « Ignorer ».
 */
final readonly class CommunityFriendSuggestionsController
{
    private const int DEFAULT_LIMIT = 6;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private FriendSuggestionsQuery $suggestions,
        private DismissFriendSuggestion $dismiss,
    ) {
    }

    #[Route('/api/v1/community/friend-suggestions', name: 'api_community_friend_suggestions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $sessionId = $request->query->get('sessionId');
        $limit = filter_var($request->query->get('limit'), \FILTER_VALIDATE_INT);

        return new JsonResponse(['data' => $this->suggestions->forViewer(
            $user->getId(),
            is_string($sessionId) && '' !== $sessionId ? $sessionId : null,
            false === $limit ? self::DEFAULT_LIMIT : $limit,
        )]);
    }

    #[Route('/api/v1/community/friend-suggestions/{slug}/ignore', name: 'api_community_friend_suggestion_ignore', methods: ['POST'])]
    public function ignore(Request $request, string $slug): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (DismissFriendSuggestionOutcome::NotFound === $this->dismiss->dismiss($user->getId(), $slug)) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(null, 204);
    }
}
