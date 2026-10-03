<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Identity\Domain\Entity\User;
use App\Sessions\Application\Command\BuyHintWithPelles;
use App\Sessions\Application\Query\PelleHintOfferQuery;
use App\Sessions\Application\Query\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hints bought with pelles on a slot page (story 41.3). Only the player of the slot (or an admin) may buy, as for
 * a hint bought with points (story 9.31).
 */
final readonly class PelleHintController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private PelleHintOfferQuery $offer,
        private BuyHintWithPelles $buy,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/pelle-hints', name: 'api_sessions_pelle_hint_offer', methods: ['GET'])]
    public function offer(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $offer = $this->offer->offer($user->getId(), $sessionId);
        if (null === $offer) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse($offer);
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/pelle-hints', name: 'api_sessions_pelle_hint_buy', methods: ['POST'])]
    public function buy(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
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
            $sessionId,
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

    private function ownsSlot(User $user, string $sessionId, int $slotIndex): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true)
            || $this->sessionQuery->doesUserOwnSlot($user->getId(), $sessionId, $slotIndex);
    }
}
