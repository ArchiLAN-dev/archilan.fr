<?php

declare(strict_types=1);

namespace App\SessionConfig\Presentation\Admin;

use App\SessionConfig\Application\ClearSessionConfigOverride;
use App\SessionConfig\Application\SessionConfigOverrideQuery;
use App\SessionConfig\Application\SetSessionConfigOverride;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-managed per-scope config override (weekly = template id, event = session id).
 * Private-run overrides are owner-managed via the PersonalRuns presentation, not here.
 */
final readonly class AdminSessionConfigOverrideController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionConfigOverrideQuery $query,
        private SetSessionConfigOverride $setOverride,
        private ClearSessionConfigOverride $clearOverride,
    ) {
    }

    #[Route('/api/v1/admin/session-config/override/{scopeKey}', name: 'api_admin_session_config_override_get', methods: ['GET'])]
    public function get(Request $request, string $scopeKey): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => ['scopeKey' => $scopeKey, 'override' => $this->query->execute($scopeKey)]]);
    }

    #[Route('/api/v1/admin/session-config/override/{scopeKey}', name: 'api_admin_session_config_override_set', methods: ['PUT'])]
    public function set(Request $request, string $scopeKey): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_body', 'request body must be a JSON object', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->setOverride->execute($scopeKey, $payload);
        } catch (\DomainException $e) {
            return $this->apiAccessGuard->errorResponse($e->getMessage(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => ['scopeKey' => $scopeKey, 'override' => $this->query->execute($scopeKey)]]);
    }

    #[Route('/api/v1/admin/session-config/override/{scopeKey}', name: 'api_admin_session_config_override_clear', methods: ['DELETE'])]
    public function clear(Request $request, string $scopeKey): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->clearOverride->execute($scopeKey);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
