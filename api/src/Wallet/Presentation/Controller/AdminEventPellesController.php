<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\DistributeEventPelles;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The pelles of an event, admin side (story 41.2): who holds what, and the distribution.
 */
final readonly class AdminEventPellesController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventPellesQueryInterface $query,
        private DistributeEventPelles $distribute,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/pelles', name: 'api_wallet_admin_event_pelles', methods: ['GET'])]
    public function show(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $page = $this->query->eventPage($eventId, $this->clock->now());
        if (null === $page) {
            return $this->apiAccessGuard->errorResponse('event_not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse($page);
    }

    #[Route('/api/v1/admin/events/{eventId}/pelles', name: 'api_wallet_admin_event_pelles_distribute', methods: ['POST'])]
    public function distribute(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $amount = $payload['amount'] ?? null;
        $userIds = null;
        if (array_key_exists('userIds', $payload)) {
            $raw = is_array($payload['userIds']) ? $payload['userIds'] : [];
            $userIds = array_values(array_filter($raw, is_string(...)));
        }

        $result = $this->distribute->distribute(
            $admin->getId(),
            $eventId,
            is_int($amount) ? $amount : 0,
            is_string($payload['label'] ?? null) ? $payload['label'] : '',
            is_string($payload['requestId'] ?? null) ? $payload['requestId'] : '',
            $userIds,
        );

        return new JsonResponse([
            'credited' => $result->credited,
            'skipped' => $result->skipped,
            'alreadyCredited' => $result->alreadyCredited,
        ]);
    }
}
