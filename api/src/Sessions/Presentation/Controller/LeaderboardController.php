<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\LeaderboardQuery;
use App\Sessions\Application\Support\SessionRecapAudience;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LeaderboardController
{
    private const array VALID_AXES = ['goals', 'checks', 'speed'];
    private const array UNITS = ['goals' => 'goals', 'checks' => 'checks', 'speed' => 'seconds'];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private LeaderboardQuery $leaderboardQuery,
        private SessionRecapAudience $audience,
    ) {
    }

    #[Route('/api/v1/leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request): JsonResponse
    {
        $axis = $request->query->get('axis', '');
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

        // Story 43.8: the viewer and their friends only; empty for an anonymous visitor.
        $friendsOnly = $request->query->getBoolean('friendsOnly');
        $viewerId = $friendsOnly || null !== $eventId ? $this->apiAccessGuard->optionalUser($request)?->getId() : null;

        // Story 50.7: a draft has no public board.
        if (null !== $eventId && $this->audience->hidesEvent($eventId, $viewerId)) {
            return $this->apiAccessGuard->errorResponse('event_not_found', 'Événement introuvable.', 404);
        }

        $unit = self::UNITS[$axis];

        if ('speed' === $axis) {
            [$entries, $total] = $this->leaderboardQuery->computeSpeedPage($eventId, $limit, $offset, $friendsOnly, $viewerId);
        } else {
            [$entries, $total] = $this->leaderboardQuery->computeAggregatePage($axis, $eventId, $limit, $offset, $friendsOnly, $viewerId);
        }

        $data = [];
        foreach ($entries as $i => $entry) {
            $data[] = [
                'rank' => $offset + $i + 1,
                'slug' => $entry['slug'],
                'displayName' => $entry['displayName'],
                'avatarUrl' => $entry['avatarUrl'],
                'avatarAnimatedUrl' => $entry['avatarAnimatedUrl'],
                'avatarFrame' => $entry['avatarFrame'],
                'avatarFraming' => $entry['avatarFraming'],
                'nameStyle' => $entry['nameStyle'],
                'title' => $entry['title'],
                'value' => $entry['value'],
                'unit' => $unit,
            ];
        }

        return new JsonResponse(
            [
                'data' => $data,
                'meta' => ['axis' => $axis, 'page' => $page, 'total' => $total],
            ],
            // A friends board is the viewer's own, an admin may read a draft's: never in a shared cache.
            headers: ['Cache-Control' => $friendsOnly || null !== $eventId ? 'private, no-store' : 'public, max-age=60'],
        );
    }
}
