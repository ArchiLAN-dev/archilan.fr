<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Identity\Domain\Entity\User;
use App\Sessions\Application\Command\PostItemBounty;
use App\Sessions\Application\Command\WithdrawItemBounty;
use App\Sessions\Application\Query\ItemBountiesQuery;
use App\Sessions\Application\Query\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bounties on items, paid in pelles (story 41.4). Read and posted from a slot page, by its player.
 */
final readonly class ItemBountyController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private ItemBountiesQuery $query,
        private PostItemBounty $post,
        private WithdrawItemBounty $withdraw,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/bounties', name: 'api_sessions_bounties_list', methods: ['GET'])]
    public function list(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $bounties = $this->query->forSession($sessionId, $user->getId());
        if (null === $bounties) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse($bounties);
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/bounties', name: 'api_sessions_bounties_post', methods: ['POST'])]
    public function post(Request $request, string $sessionId, int $slotIndex): JsonResponse
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
        $amount = $body['amount'] ?? null;

        $bounty = $this->post->post(
            $user->getId(),
            $sessionId,
            $slotIndex,
            is_string($body['itemName'] ?? null) ? $body['itemName'] : '',
            is_int($amount) ? $amount : 0,
            is_string($body['requestId'] ?? null) ? $body['requestId'] : '',
        );

        return new JsonResponse([
            'id' => $bounty->id,
            'slotName' => $bounty->slotName,
            'itemName' => $bounty->itemName,
            'amount' => $bounty->amount,
            'reward' => $bounty->reward,
            'mine' => true,
        ], 201);
    }

    #[Route('/api/v1/sessions/{sessionId}/bounties/{bountyId}', name: 'api_sessions_bounties_withdraw', methods: ['DELETE'])]
    public function withdraw(Request $request, string $sessionId, string $bountyId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->withdraw->withdraw($user->getId(), $bountyId);

        return new JsonResponse(null, 204);
    }

    private function ownsSlot(User $user, string $sessionId, int $slotIndex): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true)
            || $this->sessionQuery->doesUserOwnSlot($user->getId(), $sessionId, $slotIndex);
    }
}
