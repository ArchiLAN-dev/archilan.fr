<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\GameRequests;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GameRequestController
{
    use RequiresAuthTrait;

    public function __construct(
        private GameRequests $gameRequests,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/game-requests', name: 'api_game_requests_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->optionalUser($request);
        $requests = $this->gameRequests->list($user?->getId());

        return new JsonResponse(['data' => $requests]);
    }

    #[Route('/api/v1/game-requests', name: 'api_game_requests_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = json_decode((string) $request->getContent(), true);
        $gameName = is_array($body) && is_string($body['gameName'] ?? null) ? trim($body['gameName']) : '';

        if ('' === $gameName) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le nom du jeu est requis.', 422);
        }

        if (mb_strlen($gameName) > 255) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le nom du jeu est trop long (255 caractères max).', 422);
        }

        try {
            $this->gameRequests->submit($gameName, $user->getId(), new \DateTimeImmutable());
        } catch (UniqueConstraintViolationException) {
            return $this->apiAccessGuard->errorResponse('already_voted', 'Tu as déjà demandé ce jeu.', 409);
        }

        return new JsonResponse(null, 201);
    }

    #[Route('/api/v1/game-requests/{normalizedName}', name: 'api_game_requests_cancel', methods: ['DELETE'])]
    public function cancel(string $normalizedName, Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->gameRequests->cancel($normalizedName, $user->getId());

        return new JsonResponse(null, 204);
    }
}
