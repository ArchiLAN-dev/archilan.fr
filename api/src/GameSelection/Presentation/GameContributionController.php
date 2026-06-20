<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\MyGameTutorialContributionsQueryInterface;
use App\GameSelection\Application\SubmitGameTutorialContribution;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GameContributionController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SubmitGameTutorialContribution $submit,
        private MyGameTutorialContributionsQueryInterface $myContributions,
    ) {
    }

    #[Route('/api/v1/game-contributions', name: 'api_game_contributions_submit', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $gameSlug = is_string($payload['gameSlug'] ?? null) ? $payload['gameSlug'] : null;
        $proposedGameName = is_string($payload['proposedGameName'] ?? null) ? $payload['proposedGameName'] : null;
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $message = is_string($payload['message'] ?? null) ? $payload['message'] : null;

        $result = $this->submit->submit($user->getId(), $gameSlug, $proposedGameName, $steps, $message);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La contribution contient des erreurs.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['id' => $result['id'] ?? null], 'meta' => ['message' => 'Contribution envoyée.']], 201);
    }

    #[Route('/api/v1/game-contributions/me', name: 'api_game_contributions_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->myContributions->forAuthor($user->getId())]);
    }
}
