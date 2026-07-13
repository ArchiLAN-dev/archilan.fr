<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\SessionRecapQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionRecapController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionRecapQuery $sessionRecapQuery,
    ) {
    }

    #[Route('/api/v1/parties/{sessionId}/recap', methods: ['GET'])]
    public function recap(string $sessionId): JsonResponse
    {
        $result = $this->sessionRecapQuery->execute($sessionId);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse(
                'recap_not_found',
                'Récap introuvable, partie non terminée ou événement non public.',
                404,
            );
        }

        return new JsonResponse(['data' => $result]);
    }
}
