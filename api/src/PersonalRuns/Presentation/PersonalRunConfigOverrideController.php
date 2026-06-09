<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunConfigOverride;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Owner-managed config override for a private run (keyed by run id). Only the run owner may use it.
 */
final readonly class PersonalRunConfigOverrideController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunConfigOverride $configOverride,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/config-override', name: 'api_runs_config_override_get', methods: ['GET'])]
    public function get(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->configOverride->get($runId, $user->getId());

        return $this->respond($result);
    }

    #[Route('/api/v1/runs/{runId}/config-override', name: 'api_runs_config_override_set', methods: ['PUT'])]
    public function set(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_body', 'request body must be a JSON object', Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->configOverride->set($runId, $user->getId(), $payload);
        } catch (\DomainException $e) {
            return $this->apiAccessGuard->errorResponse($e->getMessage(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respond($result);
    }

    #[Route('/api/v1/runs/{runId}/config-override', name: 'api_runs_config_override_clear', methods: ['DELETE'])]
    public function clear(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->configOverride->clear($runId, $user->getId());
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', Response::HTTP_NOT_FOUND);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array{found: bool, authorized: bool, override?: array<string, mixed>, profile?: array<string, mixed>} $result
     */
    private function respond(array $result): JsonResponse
    {
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', Response::HTTP_NOT_FOUND);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => ['override' => $result['override'] ?? [], 'profile' => $result['profile'] ?? null]]);
    }
}
