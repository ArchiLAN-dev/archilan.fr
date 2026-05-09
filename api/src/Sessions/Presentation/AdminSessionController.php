<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Sessions\Application\SessionOrchestrator;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminSessionController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private SessionOrchestrator $sessionOrchestrator,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/sessions', methods: ['GET'])]
    public function list(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $sessions = $this->sessionOrchestrator->listSessions($eventId);

        return new JsonResponse(['data' => $sessions]);
    }

    #[Route('/api/v1/admin/events/{eventId}/sessions/builder', methods: ['GET'])]
    public function builder(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $data = $this->sessionOrchestrator->getBuilder($eventId);

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/v1/admin/events/{eventId}/sessions/preflight', methods: ['POST'])]
    public function preflight(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $body = $this->jsonBody($request);
        $rawSlotsValue = $body['slots'] ?? null;
        $rawSlots = is_array($rawSlotsValue) ? $rawSlotsValue : [];
        $slots = $this->buildSlotsList($rawSlots);

        $result = $this->sessionOrchestrator->preflight($eventId, $slots);

        if (isset($result['error']) && 'runner_unavailable' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible.', 503);
        }

        return new JsonResponse($result);
    }

    #[Route('/api/v1/admin/events/{eventId}/sessions', methods: ['POST'])]
    public function create(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $body = $this->jsonBody($request);
        $rawSlotsValue = $body['slots'] ?? null;
        $rawSlots = is_array($rawSlotsValue) ? $rawSlotsValue : [];
        $slots = $this->buildSlotsList($rawSlots);

        $validation = $this->sessionOrchestrator->validateCreation($eventId, $slots);
        if (!$validation['valid']) {
            if (isset($validation['errors']['runner'])) {
                return $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible.', 503, $validation['errors']);
            }

            return $this->apiAccessGuard->errorResponse('session_preflight_failed', 'La session contient des erreurs de validation.', 422, $validation['errors']);
        }

        $result = $this->sessionLifecycleManager->createSession($eventId, $slots);

        return new JsonResponse(['data' => $result['session']], 201);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}', methods: ['GET'])]
    public function get(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionLifecycleManager->getSession($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse(['data' => ['session' => $result['session'], 'slots' => $result['slots']]]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/status', methods: ['PATCH'])]
    public function transition(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $body = $this->jsonBody($request);
        $newStatus = is_string($body['status'] ?? null) ? $body['status'] : '';
        $hostRaw = $body['host'] ?? null;
        $host = is_string($hostRaw) ? $hostRaw : null;
        $portRaw = $body['port'] ?? null;
        $port = is_int($portRaw) ? $portRaw : null;
        $passwordRaw = $body['password'] ?? null;
        $password = is_string($passwordRaw) ? $passwordRaw : null;

        $result = $this->sessionLifecycleManager->transition($sessionId, $newStatus, $host, $port, $password);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $transitionErrors = $result['errors'] ?? null;
        if (is_array($transitionErrors)) {
            $messages = $this->toStringList($transitionErrors);

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $messages), 409, ['transition' => $messages]);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/force-reset', methods: ['POST'])]
    public function forceReset(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionLifecycleManager->forceReset($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse(['data' => $result['session'] ?? []]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}', methods: ['DELETE'])]
    public function stop(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionLifecycleManager->transition($sessionId, 'stopped');

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $transitionErrors = $result['errors'] ?? null;
        if (is_array($transitionErrors)) {
            $messages = $this->toStringList($transitionErrors);

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $messages), 409, ['transition' => $messages]);
        }

        return new JsonResponse(null, 204);
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

    /**
     * @param array<mixed> $rawSlots
     *
     * @return list<array{registrationId: string, gameId: string, slotName: string, slotId: string|null}>
     */
    private function buildSlotsList(array $rawSlots): array
    {
        $slots = [];
        foreach ($rawSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $registrationId = is_string($slot['registrationId'] ?? null) ? $slot['registrationId'] : '';
            $gameId = is_string($slot['gameId'] ?? null) ? $slot['gameId'] : '';
            $slotName = is_string($slot['slotName'] ?? null) ? $slot['slotName'] : '';
            $slotIdRaw = $slot['slotId'] ?? null;
            $slotId = is_string($slotIdRaw) ? $slotIdRaw : null;
            $slots[] = [
                'registrationId' => $registrationId,
                'gameId' => $gameId,
                'slotName' => $slotName,
                'slotId' => $slotId,
            ];
        }

        return $slots;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private function toStringList(array $list): array
    {
        $result = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
