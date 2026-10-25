<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Service\BlindTestSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An event's blind test, admin side (story 41.40): on or off, what a source is worth, the cap per member, and
 * whether members not registered earn (story 41.54).
 */
final readonly class AdminBlindTestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private BlindTestSettings $settings,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/blind-test', name: 'api_wallet_admin_blind_test', methods: ['GET'])]
    public function show(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->settings->of($eventId)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/blind-test', name: 'api_wallet_admin_blind_test_update', methods: ['PUT'])]
    public function update(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        return new JsonResponse(['data' => $this->settings->change(
            $eventId,
            true === ($payload['enabled'] ?? null),
            is_int($payload['pellesPerSource'] ?? null) ? $payload['pellesPerSource'] : 0,
            is_int($payload['capPerPlayer'] ?? null) ? $payload['capPerPlayer'] : 0,
            is_bool($payload['pellesForUnregistered'] ?? null) ? $payload['pellesForUnregistered'] : null,
        )]);
    }
}
