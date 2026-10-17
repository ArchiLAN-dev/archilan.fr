<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\SetRunParticipantReady;
use App\PersonalRuns\Application\Command\SetRunParticipantReadyOutcome;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A player of a draft run says they are ready (PUT) or no longer are (DELETE) - story 16.23.
 */
final readonly class RunReadyController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SetRunParticipantReady $setReady,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/ready', name: 'api_runs_ready', methods: ['PUT', 'DELETE'])]
    public function __invoke(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $ready = Request::METHOD_PUT === $request->getMethod();

        return match ($this->setReady->set($runId, $user->getId(), $ready)) {
            SetRunParticipantReadyOutcome::Updated => new JsonResponse(['data' => ['ready' => $ready]]),
            SetRunParticipantReadyOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            SetRunParticipantReadyOutcome::Launched => $this->apiAccessGuard->errorResponse('run_launched', 'La partie est déjà lancée.', 409),
            SetRunParticipantReadyOutcome::NothingToPlay => $this->apiAccessGuard->errorResponse('nothing_to_play', 'Choisis d\'abord un jeu, ou rejoins le slot d\'un autre joueur.', 422),
        };
    }
}
