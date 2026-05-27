<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\NotifyAllGoalCommand;
use App\Sessions\Domain\SessionNotFoundException;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AllGoalController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private NotifyAllGoalCommand $notifyAllGoalCommand,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{id}/all-goal', methods: ['POST'])]
    public function allGoal(Request $request, string $id): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret runner invalide.', 401);
        }

        try {
            $this->notifyAllGoalCommand->execute($id);
        } catch (SessionNotFoundException) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
