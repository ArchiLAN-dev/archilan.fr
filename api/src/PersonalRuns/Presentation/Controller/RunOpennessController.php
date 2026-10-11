<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\JoinOpenRun;
use App\PersonalRuns\Application\Command\JoinOpenRunOutcome;
use App\PersonalRuns\Application\Command\SetRunOpenness;
use App\PersonalRuns\Application\Command\SetRunOpennessOutcome;
use App\PersonalRuns\Application\Query\FriendsOpenRunsQuery;
use App\PersonalRuns\Application\Query\OpenRunListingsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A draft run opened to its owner's friends (story 43.14) or listed for every member (story 43.17): the owner's
 * setting, the lists, joining.
 */
final readonly class RunOpennessController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SetRunOpenness $setOpenness,
        private JoinOpenRun $joinOpenRun,
        private FriendsOpenRunsQuery $openRuns,
        private OpenRunListingsQuery $listings,
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
            $decoded = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        $payload = is_array($decoded) ? $decoded : [];
        $openness = is_string($payload['openness'] ?? null) ? $payload['openness'] : '';
        $seats = $payload['seatsWanted'] ?? null;
        if (null !== $seats && !is_int($seats)) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'Nombre de places invalide.', 422);
        }
        // Story 43.17: a listing for every member carries a message and an optional date.
        $pitch = is_string($payload['pitch'] ?? null) ? $payload['pitch'] : null;
        $plannedForRaw = is_string($payload['plannedFor'] ?? null) && '' !== $payload['plannedFor'] ? $payload['plannedFor'] : null;
        try {
            $plannedFor = null === $plannedForRaw ? null : new \DateTimeImmutable($plannedForRaw);
        } catch (\Exception) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'Date prévue invalide.', 422);
        }

        return match ($this->setOpenness->set($runId, $user->getId(), $openness, $seats, $pitch, $plannedFor)) {
            SetRunOpennessOutcome::Updated => new JsonResponse(['data' => ['openness' => $openness, 'seatsWanted' => in_array($openness, [SetRunOpenness::OPEN_FRIENDS, SetRunOpenness::OPEN_MEMBERS], true) ? $seats : null]]),
            SetRunOpennessOutcome::Sanctioned => $this->apiAccessGuard->errorResponse('sanctioned', 'Ton compte est suspendu : tu ne peux pas publier d\'annonce.', 403),
            SetRunOpennessOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            SetRunOpennessOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seul le créateur de la partie règle son ouverture.', 403),
            SetRunOpennessOutcome::Locked => $this->apiAccessGuard->errorResponse('run_locked', 'La partie est lancée : son ouverture ne change plus.', 409),
            SetRunOpennessOutcome::Invalid => $this->apiAccessGuard->errorResponse('invalid_payload', sprintf('Ouverture inconnue, places hors de 1 à %d, ou annonce sans message (%d caractères au plus).', SetRunOpenness::MAX_SEATS_WANTED, SetRunOpenness::MAX_PITCH_LENGTH), 422),
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

    /** Story 43.17: « Parties qui cherchent des joueurs », for signed-in members. */
    #[Route('/api/v1/run-listings', name: 'api_run_listings', methods: ['GET'])]
    public function listings(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->listings->forViewer($user->getId())]);
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
            JoinOpenRunOutcome::Sanctioned => $this->apiAccessGuard->errorResponse('sanctioned', 'Ton compte est suspendu : tu ne peux pas rejoindre de partie.', 403),
        };
    }
}
