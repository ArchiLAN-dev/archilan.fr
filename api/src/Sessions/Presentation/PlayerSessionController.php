<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\PlayerSessionConnection;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlayerSessionController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PlayerSessionConnection $playerSessionConnection,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/session-connection', methods: ['GET'])]
    public function getConnection(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->playerSessionConnection->getConnection($registrationId, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }
}
