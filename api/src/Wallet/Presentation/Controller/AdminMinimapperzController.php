<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Service\MinimapperzSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An event's link to Minimapperz, admin side (story 49.1): slug, API key (written, never read back), what a game is
 * worth in pelles, and a connection check.
 */
final readonly class AdminMinimapperzController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MinimapperzSettings $settings,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz', name: 'api_wallet_admin_minimapperz', methods: ['GET'])]
    public function show(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->settings->of($eventId)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz', name: 'api_wallet_admin_minimapperz_update', methods: ['PUT'])]
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
            is_string($payload['slug'] ?? null) ? $payload['slug'] : '',
            is_string($payload['apiKey'] ?? null) ? $payload['apiKey'] : null,
            true === ($payload['clearApiKey'] ?? null),
            is_int($payload['pellesPerGame'] ?? null) ? $payload['pellesPerGame'] : -1,
            is_int($payload['pellesPerWin'] ?? null) ? $payload['pellesPerWin'] : -1,
            is_int($payload['capPerPlayer'] ?? null) ? $payload['capPerPlayer'] : 0,
        )]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/check', name: 'api_wallet_admin_minimapperz_check', methods: ['POST'])]
    public function check(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->settings->checkConnection($eventId)]);
    }
}
