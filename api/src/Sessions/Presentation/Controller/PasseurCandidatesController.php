<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\PasseurCandidate;
use App\Sessions\Application\Query\PasseurCandidatesQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 52.3: the Passeur's candidates for one slot, for an admin to check the computation on a real game before
 * the Passeur opens anything (52.4).
 */
final readonly class PasseurCandidatesController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PasseurCandidatesQuery $candidates,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/slots/{slot}/passeur-candidates', requirements: ['slot' => '\d+'], methods: ['GET'])]
    public function candidates(Request $request, string $id, int $slot): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->candidates->forSlot($id, $slot);

        return new JsonResponse(['data' => [
            'blocked' => null !== $result->blockedSince,
            'blockedSince' => $result->blockedSince?->format(\DateTimeInterface::ATOM),
            'absentSlots' => $result->absentSlots,
            'ranking' => $result->ranking,
            'candidates' => array_map(static fn (PasseurCandidate $candidate): array => [
                'findingSlot' => $candidate->item->findingSlot,
                'findingPlayerName' => $candidate->item->findingPlayerName,
                'locationId' => $candidate->item->locationId,
                'locationName' => $candidate->item->locationName,
                'itemId' => $candidate->item->itemId,
                'itemName' => $candidate->item->itemName,
                'sphere' => $candidate->item->sphere,
                'unlocks' => $candidate->unlocks,
            ], $result->candidates),
        ]]);
    }
}
