<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\ForceEndSessionCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ForceEndController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ForceEndSessionCommand $forceEndSessionCommand,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/force-end', methods: ['POST'])]
    public function forceEnd(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->forceEndSessionCommand->execute($id, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if ($result['notRunning']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        return new JsonResponse(['data' => $result['payload']]);
    }
}
