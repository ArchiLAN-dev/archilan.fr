<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunLifecycle;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PersonalRunCallbackController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunLifecycle $lifecycle,
        private string $bridgeInternalToken,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/running', name: 'api_runs_callback_running', methods: ['POST'])]
    public function running(Request $request, string $runId): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret runner invalide.', 401);
        }

        $body = $this->jsonBody($request);
        $host = is_string($body['connectionHost'] ?? null) ? $body['connectionHost'] : '';
        $portRaw = $body['connectionPort'] ?? null;
        $port = is_int($portRaw) ? $portRaw : 0;

        if ('' === $host || $port <= 0) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'connectionHost et connectionPort sont requis.', 422);
        }

        $result = $this->lifecycle->markRunning($runId, $host, $port);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse(
                $result['blockReason'] ?? 'invalid_run_status',
                'Transition de run invalide.',
                422,
            );
        }

        return new JsonResponse(['data' => ['runId' => $result['runId'], 'status' => $result['status']]]);
    }

    #[Route('/api/v1/runs/{runId}/stopped', name: 'api_runs_callback_stopped', methods: ['POST'])]
    public function stopped(Request $request, string $runId): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret runner invalide.', 401);
        }

        $result = $this->lifecycle->markStopped($runId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse(
                $result['blockReason'] ?? 'invalid_run_status',
                'Transition de run invalide.',
                422,
            );
        }

        return new JsonResponse(['data' => ['runId' => $result['runId'], 'status' => $result['status']]]);
    }

    private function isAuthorized(Request $request): bool
    {
        $auth = $request->headers->get('Authorization') ?? '';

        return '' !== $this->bridgeInternalToken
            && str_starts_with($auth, 'Bearer ')
            && substr($auth, 7) === $this->bridgeInternalToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return [];
            }

            $result = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $result[$key] = $value;
                }
            }

            return $result;
        } catch (\JsonException) {
            return [];
        }
    }
}
