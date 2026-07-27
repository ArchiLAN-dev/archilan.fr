<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\SessionRecapQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionRecapController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionRecapQuery $sessionRecapQuery,
    ) {
    }

    #[Route('/api/v1/parties/{sessionId}/recap', methods: ['GET'])]
    public function recap(Request $request, string $sessionId): JsonResponse
    {
        // Auth is optional: an event or published personal-run recap is public, while a private
        // personal-run recap is only served to its owner/participants (story 32.5).
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $result = $this->sessionRecapQuery->execute($sessionId, $viewer?->getId());
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse(
                'recap_not_found',
                'Récap introuvable, partie non terminée ou accès non autorisé.',
                404,
            );
        }

        return new JsonResponse(['data' => $result]);
    }
}
