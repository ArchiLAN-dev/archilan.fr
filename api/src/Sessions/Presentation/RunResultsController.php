<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\RunResultsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RunResultsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private RunResultsQuery $runResultsQuery,
    ) {
    }

    #[Route('/api/v1/runs/{id}/results', methods: ['GET'])]
    public function results(string $id): JsonResponse
    {
        $result = $this->runResultsQuery->execute($id);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse(
                'run_not_found_or_not_finished',
                'Run introuvable ou non terminé.',
                404,
            );
        }

        return new JsonResponse(['data' => $result]);
    }
}
