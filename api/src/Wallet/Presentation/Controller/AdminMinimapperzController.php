<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Query\MinimapperzPlayers;
use App\Wallet\Application\Service\MinimapperzCodes;
use App\Wallet\Application\Service\MinimapperzSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An event's link to Minimapperz, admin side (story 49.1): slug, API key (written, never read back), what a game is
 * worth in pelles, and a connection check. Story 49.2: the codes Minimapperz gave, imported and handed to the
 * registrants. Story 49.3: the players seen on Minimapperz, and who among them is linked to no registrant.
 */
final readonly class AdminMinimapperzController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MinimapperzSettings $settings,
        private MinimapperzCodes $codes,
        private MinimapperzPlayers $players,
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

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/codes', name: 'api_wallet_admin_minimapperz_codes', methods: ['GET'])]
    public function codes(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->codes->overview($eventId)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/codes', name: 'api_wallet_admin_minimapperz_codes_import', methods: ['POST'])]
    public function importCodes(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_string($payload['codes'] ?? null)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        return new JsonResponse(['data' => $this->codes->import($eventId, $payload['codes'])]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/codes/assign', name: 'api_wallet_admin_minimapperz_codes_assign', methods: ['POST'])]
    public function assignCodes(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->codes->assignToAll($eventId)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/codes/{code}/assignment', name: 'api_wallet_admin_minimapperz_code_detach', requirements: ['code' => '.+'], methods: ['DELETE'])]
    public function detachCode(Request $request, string $eventId, string $code): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->codes->detach($eventId, $code)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/minimapperz/players', name: 'api_wallet_admin_minimapperz_players', methods: ['GET'])]
    public function players(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->players->of($eventId)]);
    }
}
