<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\ReportRunListingService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 43.17: a member reports a run listing, into the moderation queue.
 */
final readonly class RunListingReportController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ReportRunListingService $reportRunListing,
    ) {
    }

    #[Route('/api/v1/community/run-listings/{runId}/report', name: 'api_community_run_listing_report', methods: ['POST'])]
    public function __invoke(Request $request, string $runId): JsonResponse
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
        $problem = is_array($payload) && is_string($payload['problem'] ?? null) ? $payload['problem'] : '';
        $comment = is_array($payload) && is_string($payload['comment'] ?? null) ? $payload['comment'] : null;

        return match ($this->reportRunListing->report($user->getId(), $runId, $problem, $comment)) {
            'ok' => new JsonResponse(null, 204),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Tu ne peux pas signaler ta propre annonce.', 403),
            'invalid' => $this->apiAccessGuard->errorResponse('invalid_report', 'Signalement invalide.', 422),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Annonce introuvable.', 404),
        };
    }
}
