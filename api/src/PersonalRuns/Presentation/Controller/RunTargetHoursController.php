<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\SetRunTargetHours;
use App\PersonalRuns\Application\Command\SetRunTargetHoursOutcome;
use App\PersonalRuns\Domain\Entity\Run;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 56.5: how long a world should last in a private run. */
final readonly class RunTargetHoursController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SetRunTargetHours $setTargetHours,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/target-hours', name: 'api_runs_target_hours', methods: ['PUT'])]
    public function set(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $body = json_decode($request->getContent(), true);
        $hours = is_array($body) ? ($body['targetHours'] ?? null) : null;
        if (null !== $hours && !is_int($hours)) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'targetHours est un nombre d\'heures ou null.', 422);
        }

        return match ($this->setTargetHours->set($runId, $user->getId(), $hours)) {
            SetRunTargetHoursOutcome::Done => new JsonResponse(['data' => ['targetHours' => $hours]]),
            SetRunTargetHoursOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            SetRunTargetHoursOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seuls les organisateurs de la partie règlent sa durée cible.', 403),
            SetRunTargetHoursOutcome::Invalid => $this->apiAccessGuard->errorResponse('validation_failed', sprintf('La durée cible est un nombre d\'heures entre 1 et %d.', Run::MAX_TARGET_HOURS), 422),
        };
    }
}
