<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\LeaderboardQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LeaderboardController
{
    private const VALID_AXES = ['goals', 'checks', 'speed'];
    private const UNITS = ['goals' => 'goals', 'checks' => 'checks', 'speed' => 'seconds'];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private LeaderboardQuery $leaderboardQuery,
    ) {
    }

    #[Route('/api/v1/leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request): JsonResponse
    {
        $axis = (string) $request->query->get('axis', '');
        if (!in_array($axis, self::VALID_AXES, true)) {
            return $this->apiAccessGuard->errorResponse(
                'invalid_axis',
                sprintf('Axe invalide. Valeurs acceptées : %s.', implode(', ', self::VALID_AXES)),
                422,
            );
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = max(1, min(100, (int) $request->query->get('limit', '20')));
        $offset = ($page - 1) * $limit;
        $eventId = $request->query->has('eventId') ? (string) $request->query->get('eventId') : null;

        $unit = self::UNITS[$axis];

        if ('speed' === $axis) {
            [$entries, $total] = $this->leaderboardQuery->computeSpeedPage($eventId, $limit, $offset);
        } else {
            [$entries, $total] = $this->leaderboardQuery->computeAggregatePage($axis, $eventId, $limit, $offset);
        }

        $data = [];
        foreach ($entries as $i => $entry) {
            $data[] = [
                'rank' => $offset + $i + 1,
                'slug' => $entry['slug'],
                'displayName' => $entry['displayName'],
                'value' => $entry['value'],
                'unit' => $unit,
            ];
        }

        return new JsonResponse(
            [
                'data' => $data,
                'meta' => ['axis' => $axis, 'page' => $page, 'total' => $total],
            ],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
