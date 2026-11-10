<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Identity\Domain\Entity\User;
use App\Sessions\Application\Command\CallThePasseur;
use App\Sessions\Application\Query\PasseurOfferQuery;
use App\Sessions\Application\Query\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 52.4: the Passeur on a slot page - its proposal, and the call. Only the player of the slot (or an admin), as
 * for a hint bought with pelles.
 */
final readonly class PasseurController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private PasseurOfferQuery $offer,
        private CallThePasseur $call,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/passeur', name: 'api_sessions_passeur_offer', requirements: ['slotIndex' => '\d+'], methods: ['GET'])]
    public function offer(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $result = $this->offer->forPlayer($user->getId(), $sessionId, $slotIndex);
        $offer = $result['offer'];

        return new JsonResponse(['data' => [
            'enabled' => $result['enabled'],
            'offer' => null === $offer ? null : [
                'findingSlot' => $offer->findingSlot,
                'absentPlayerName' => $offer->absentPlayerName,
                'locationId' => $offer->locationId,
                'locationName' => $offer->locationName,
                'itemName' => $offer->itemName,
                'price' => $offer->price,
                'basePrice' => $offer->basePrice,
                'kind' => $offer->kind,
                'balance' => $offer->balance,
                'blockedSince' => $offer->blockedSince->format(\DateTimeInterface::ATOM),
                'nextDropAt' => $offer->nextDropAt?->format(\DateTimeInterface::ATOM),
                'availableAt' => $offer->availableAt?->format(\DateTimeInterface::ATOM),
            ],
        ]]);
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/passeur', name: 'api_sessions_passeur_call', requirements: ['slotIndex' => '\d+'], methods: ['POST'])]
    public function call(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $body = json_decode($request->getContent(), true);
        $findingSlot = is_array($body) ? ($body['findingSlot'] ?? null) : null;
        $locationId = is_array($body) ? ($body['locationId'] ?? null) : null;
        $price = is_array($body) ? ($body['price'] ?? null) : null;
        $requestId = is_array($body) ? ($body['requestId'] ?? null) : null;
        if (!is_int($findingSlot) || !is_int($locationId) || !is_int($price) || !is_string($requestId)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'findingSlot, locationId, price et requestId sont requis.', 422);
        }

        $result = $this->call->execute($user->getId(), $sessionId, $slotIndex, $findingSlot, $locationId, $price, $requestId);

        return new JsonResponse(['data' => [
            'kind' => $result->kind,
            'price' => $result->price,
            'share' => $result->share,
            'itemName' => $result->itemName,
            'absentPlayerName' => $result->absentPlayerName,
            'replayed' => $result->replayed,
        ]]);
    }

    private function ownsSlot(User $user, string $sessionId, int $slotIndex): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true)
            || $this->sessionQuery->doesUserOwnSlot($user->getId(), $sessionId, $slotIndex);
    }
}
