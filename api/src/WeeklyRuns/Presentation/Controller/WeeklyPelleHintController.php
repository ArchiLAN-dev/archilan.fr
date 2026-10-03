<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\WeeklyRuns\Application\Command\BuyWeeklyHintWithPelles;
use App\WeeklyRuns\Application\Query\WeeklyPelleHintOfferQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hints bought with pelles on a weekly slot page (story 41.8), the same contract as on a session (story 41.3).
 */
final readonly class WeeklyPelleHintController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyPelleHintOfferQuery $offer,
        private BuyWeeklyHintWithPelles $buy,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/pelle-hints', name: 'api_weekly_pelle_hint_offer', methods: ['GET'])]
    public function offer(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $offer = $this->offer->offer($user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true), $runId, $entryId);

        return match ($offer) {
            'not_found' => $this->apiAccessGuard->errorResponse('not_found', 'Tentative introuvable.', 404),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403),
            default => new JsonResponse($offer),
        };
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/pelle-hints', name: 'api_weekly_pelle_hint_buy', methods: ['POST'])]
    public function buy(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Corps de requête invalide.', 422);
        }
        $kind = $body['kind'] ?? null;
        if ('item' !== $kind && 'location' !== $kind) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'kind vaut item ou location.', 422);
        }
        $itemName = $body['itemName'] ?? null;
        $locationId = $body['locationId'] ?? null;

        $purchase = $this->buy->buy(
            $user->getId(),
            in_array('ROLE_ADMIN', $user->getRoles(), true),
            $runId,
            $entryId,
            $slotIndex,
            $kind,
            is_string($itemName) ? $itemName : null,
            is_int($locationId) ? $locationId : null,
            is_string($body['requestId'] ?? null) ? $body['requestId'] : '',
        );

        return new JsonResponse([
            'paidWith' => $purchase->paidWith,
            'price' => $purchase->price,
            'balanceAfter' => $purchase->balanceAfter,
            'alreadyBought' => $purchase->alreadyBought,
        ]);
    }
}
