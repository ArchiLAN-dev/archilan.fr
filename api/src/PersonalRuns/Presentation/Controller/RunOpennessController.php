<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\JoinOpenRun;
use App\PersonalRuns\Application\Command\JoinOpenRunOutcome;
use App\PersonalRuns\Application\Command\SetRunOpenness;
use App\PersonalRuns\Application\Command\SetRunOpennessOutcome;
use App\PersonalRuns\Application\Query\FriendsOpenRunsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A draft run opened to its owner's friends (story 43.14): the owner's setting, the friends' list, joining.
 */
final readonly class RunOpennessController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SetRunOpenness $setOpenness,
        private JoinOpenRun $joinOpenRun,
        private FriendsOpenRunsQuery $openRuns,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/openness', name: 'api_runs_openness', methods: ['PUT'])]
    public function set(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $openness = is_array($payload) && is_string($payload['openness'] ?? null) ? $payload['openness'] : '';
        $seats = is_array($payload) ? ($payload['seatsWanted'] ?? null) : null;
        if (null !== $seats && !is_int($seats)) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'Nombre de places invalide.', 422);
        }

        return match ($this->setOpenness->set($runId, $user->getId(), $openness, $seats)) {
            SetRunOpennessOutcome::Updated => new JsonResponse(['data' => ['openness' => $openness, 'seatsWanted' => SetRunOpenness::OPEN_FRIENDS === $openness ? $seats : null]]),
            SetRunOpennessOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            SetRunOpennessOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seul le créateur de la partie règle son ouverture.', 403),
            SetRunOpennessOutcome::Locked => $this->apiAccessGuard->errorResponse('run_locked', 'La partie est lancée : son ouverture ne change plus.', 409),
            SetRunOpennessOutcome::Invalid => $this->apiAccessGuard->errorResponse('invalid_payload', sprintf('Ouverture inconnue, ou nombre de places hors de 1 à %d.', SetRunOpenness::MAX_SEATS_WANTED), 422),
        };
    }

    #[Route('/api/v1/account/friends-open-runs', name: 'api_account_friends_open_runs', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->openRuns->forViewer($user->getId())]);
    }

    #[Route('/api/v1/runs/{runId}/join-open', name: 'api_runs_join_open', methods: ['POST'])]
    public function join(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return match ($this->joinOpenRun->join($runId, $user->getId())) {
            JoinOpenRunOutcome::Joined => new JsonResponse(['data' => ['runId' => $runId]]),
            JoinOpenRunOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Cette partie n\'est plus ouverte.', 404),
            JoinOpenRunOutcome::Full => $this->apiAccessGuard->errorResponse('run_full', 'Toutes les places sont prises.', 409),
        };
    }
}
