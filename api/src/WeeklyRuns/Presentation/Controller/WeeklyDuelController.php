<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\Service\WeeklyDuelService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Duels between friends on a weekly run (story 43.15).
 */
final readonly class WeeklyDuelController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyDuelService $duels,
    ) {
    }

    #[Route('/api/v1/weekly-duels', name: 'api_weekly_duels_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $weeklyRunId = $request->query->get('weeklyRun');

        return new JsonResponse(['data' => $this->duels->forViewer($user->getId(), is_string($weeklyRunId) && '' !== $weeklyRunId ? $weeklyRunId : null)]);
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/duels', name: 'api_weekly_duels_challenge', methods: ['POST'])]
    public function challenge(Request $request, string $weeklyRunId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->duels->challenge($weeklyRunId, $user->getId(), self::userIds($request));

        return match ($result['outcome']) {
            WeeklyDuelService::OK => new JsonResponse(['data' => ['duelId' => $result['duelId']]], 201),
            WeeklyDuelService::RUN_ENDED => $this->apiAccessGuard->errorResponse('run_ended', 'Cette hebdo est terminée.', 409),
            WeeklyDuelService::NO_FRIEND => $this->apiAccessGuard->errorResponse('no_friend', 'Choisis au moins un ami.', 422),
            WeeklyDuelService::TOO_MANY => $this->apiAccessGuard->errorResponse('too_many', sprintf('Pas plus de %d amis par duel.', WeeklyDuelService::MAX_OPPONENTS), 422),
            WeeklyDuelService::WEEK_LIMIT => $this->apiAccessGuard->errorResponse('week_limit', sprintf('Pas plus de %d duels par semaine.', WeeklyDuelService::MAX_PER_WEEK), 429),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Hebdo introuvable.', 404),
        };
    }

    #[Route('/api/v1/weekly-duels/{duelId}/accept', name: 'api_weekly_duels_accept', methods: ['POST'])]
    public function accept(Request $request, string $duelId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answered($this->duels->accept($duelId, $user->getId()));
    }

    #[Route('/api/v1/weekly-duels/{duelId}/decline', name: 'api_weekly_duels_decline', methods: ['POST'])]
    public function decline(Request $request, string $duelId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answered($this->duels->decline($duelId, $user->getId()));
    }

    private function answered(string $outcome): JsonResponse
    {
        return match ($outcome) {
            WeeklyDuelService::OK => new JsonResponse(['data' => ['ok' => true]]),
            WeeklyDuelService::CLOSED => $this->apiAccessGuard->errorResponse('duel_closed', 'Ce duel n\'attend plus de réponse.', 409),
            WeeklyDuelService::BLOCKED => $this->apiAccessGuard->errorResponse('duel_closed', 'Ce duel n\'est plus valable.', 409),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Duel introuvable.', 404),
        };
    }

    /** @return list<string> */
    private static function userIds(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $ids = is_array($payload) ? ($payload['userIds'] ?? null) : null;

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }
}
