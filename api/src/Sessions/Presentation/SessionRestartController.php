<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionRestartController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private string $bridgeInternalToken,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/restart', methods: ['POST'])]
    public function restart(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $result = $this->sessionLifecycleManager->initiateRestart($sessionId, $user->getId(), $isAdmin);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if ('forbidden' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès non autorisé.', 403);
        }

        if ('invalid_session_status' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('invalid_session_status', 'La session n\'est pas en état idle.', 422);
        }

        if ('no_save_available' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('no_save_available', 'Aucune sauvegarde disponible pour cette session.', 422);
        }

        return new JsonResponse(['data' => ['sessionId' => $result['sessionId'], 'status' => $result['status']]], 202);
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/restarting', methods: ['POST'])]
    public function restarting(Request $request, string $sessionId): JsonResponse
    {
        if (!$this->bearerTokenValid($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Token invalide.', 401);
        }

        $result = $this->sessionLifecycleManager->markRestartingBridge($sessionId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (null !== $result['error'] && 'already_restarting' !== $result['status']) {
            return $this->apiAccessGuard->errorResponse('invalid_status', $result['error'], 409);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/restart-failed', methods: ['POST'])]
    public function restartFailed(Request $request, string $sessionId): JsonResponse
    {
        if (!$this->bearerTokenValid($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Token invalide.', 401);
        }

        $result = $this->sessionLifecycleManager->markRestartFailed($sessionId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (null !== $result['error']) {
            return $this->apiAccessGuard->errorResponse('invalid_status', $result['error'], 409);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    #[Route('/api/v1/sessions/{sessionId}/restarted', methods: ['POST'])]
    public function restarted(Request $request, string $sessionId): JsonResponse
    {
        if (!$this->bearerTokenValid($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Token invalide.', 401);
        }

        $body = $this->jsonBody($request);

        $host = is_string($body['connectionHost'] ?? null) ? $body['connectionHost'] : '';
        $port = is_int($body['connectionPort'] ?? null) ? $body['connectionPort'] : 0;
        $bridgePort = is_int($body['bridgePort'] ?? null) ? $body['bridgePort'] : 0;

        $result = $this->sessionLifecycleManager->recordRestarted($sessionId, $host, $port, $bridgePort);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if ('unexpected_status' === $result['status']) {
            return $this->apiAccessGuard->errorResponse('unexpected_status', 'Statut de session inattendu.', 422);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    private function bearerTokenValid(Request $request): bool
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
