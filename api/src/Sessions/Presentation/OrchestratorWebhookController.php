<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Sessions\Application\SessionOrchestrator;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrchestratorWebhookController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private SessionOrchestrator $sessionOrchestrator,
        private LoggerInterface $logger,
        private string $orchestrateurWebhookSecret,
    ) {
        if ('' === $this->orchestrateurWebhookSecret) {
            throw new \LogicException('ORCHESTRATEUR_WEBHOOK_SECRET must not be empty.');
        }
    }

    #[Route('/api/v1/internal/orchestrateur/webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Signature invalide.', 401);
        }

        $body = $this->jsonBody($request);
        $event = is_string($body['event'] ?? null) ? $body['event'] : '';
        $sessionId = is_string($body['sessionId'] ?? null) ? $body['sessionId'] : '';

        if ('' === $sessionId) {
            return $this->apiAccessGuard->errorResponse('bad_request', 'sessionId manquant.', 400);
        }

        if ('session.generated' === $event) {
            $result = $this->sessionLifecycleManager->transition($sessionId, 'generated');
            if (!($result['found'] ?? false)) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }
            $this->sessionOrchestrator->autoAdvancePersonalRun($sessionId);

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        if ('session.ready' === $event) {
            $portRaw = $body['port'] ?? null;
            $apPort = is_int($portRaw) ? $portRaw : 0;
            $bridgePortRaw = $body['bridgePort'] ?? null;
            $bridgePort = is_int($bridgePortRaw) ? $bridgePortRaw : null;

            $result = $this->sessionLifecycleManager->transitionToRunningFromOrchestrateur($sessionId, $apPort, $bridgePort);
            if (!($result['found'] ?? false)) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        if ('session.crashed' === $event) {
            $result = $this->sessionLifecycleManager->transition($sessionId, 'crashed');
            if (!($result['found'] ?? false)) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        if ('session.stopped' === $event) {
            $this->sessionLifecycleManager->transition($sessionId, 'stopped');
            $this->sessionOrchestrator->markPersonalRunStopped($sessionId);

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    private function verifySignature(Request $request): bool
    {
        $signature = $request->headers->get('x-signature-256');
        if (null === $signature || '' === $signature) {
            return false;
        }

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $body, $this->orchestrateurWebhookSecret);

        return hash_equals($expected, $signature);
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
        } catch (\JsonException $e) {
            $this->logger->warning('orchestrateur.webhook.invalid_json', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
