<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\SessionFeedQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionFeedController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionFeedQuery $sessionFeedQuery,
    ) {
    }

    #[Route('/api/v1/parties/{sessionId}/feed', methods: ['GET'])]
    public function feed(Request $request, string $sessionId): JsonResponse|StreamedJsonResponse
    {
        // Optional auth, same rule as the recap: an event or published personal-run feed is public,
        // a private personal-run feed is served only to its owner/participants (story 32.6).
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $events = $this->sessionFeedQuery->execute($sessionId, $viewer?->getId());
        if (null === $events) {
            return $this->apiAccessGuard->errorResponse('feed_not_found', 'Feed introuvable ou accès non autorisé.', 404);
        }

        // Streamed: a long run's feed is encoded entry by entry, never held whole in memory.
        return new StreamedJsonResponse(['data' => $events]);
    }
}
