<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\SubmitGameTutorialContribution;
use App\GameSelection\Application\Query\MyGameTutorialContributionsQueryInterface;
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

        // Failures (game missing/unavailable, invalid submission) are thrown as typed ApplicationFailures
        // and mapped to HTTP by ApplicationFailureListener (epic 35).
        $id = $this->submit->submit($user->getId(), $gameSlug, $proposedGameName, $steps, $message);

        return new JsonResponse(['data' => ['id' => $id], 'meta' => ['message' => 'Contribution envoyée.']], 201);
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
