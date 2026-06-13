<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\RecordSessionGeneratedOutput;
use App\Sessions\Application\SessionLifecycleManager;
use App\Sessions\Application\SessionOrchestrator;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\MarkWeeklyRunGenerated;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OrchestratorWebhookController
{
    /**
     * Stored as the session's lastSaveKey when it goes idle via auto_shutdown. The real save lives
     * in the orchestrateur session volume (relaunch-from-save reads it there); this marker only
     * makes the session resumable through the existing "has a save" gate in initiateRestart().
     */
    private const VOLUME_SAVE_MARKER = 'orchestrateur:volume';

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private SessionOrchestrator $sessionOrchestrator,
        private MarkWeeklyRunGenerated $markWeeklyRunGenerated,
        private RecordSessionGeneratedOutput $recordSessionGeneratedOutput,
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

        // Weekly-run generator sessions are handled out-of-band: they mark the run
        // launchable (and clean themselves up) instead of going through the personal
        // session lifecycle.
        if (str_starts_with($sessionId, WeeklyRunGeneratorInterface::GENERATOR_SESSION_PREFIX)) {
            $weeklyRunId = substr($sessionId, \strlen(WeeklyRunGeneratorInterface::GENERATOR_SESSION_PREFIX));

            if ('session.generated' === $event) {
                $outputKey = is_string($body['outputKey'] ?? null) ? $body['outputKey'] : '';
                $this->markWeeklyRunGenerated->execute($weeklyRunId, $outputKey);
            } elseif ('session.crashed' === $event) {
                $this->logger->error('weekly_runs.generate.failed', [
                    'weeklyRunId' => $weeklyRunId,
                    'error' => is_string($body['error'] ?? null) ? $body['error'] : '',
                ]);
            }

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        if ('session.generated' === $event) {
            $result = $this->sessionLifecycleManager->transition($sessionId, 'generated');
            if (!($result['found'] ?? false)) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }

            // Persist the generated output archive key so the owner/admin can later download
            // the spoiler from durable storage even when the run is idle/stopped (story 16.8).
            $outputKey = is_string($body['outputKey'] ?? null) ? $body['outputKey'] : '';
            $this->recordSessionGeneratedOutput->execute($sessionId, $outputKey);

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
            $reason = is_string($body['error'] ?? null) ? $body['error'] : null;
            $result = $this->sessionLifecycleManager->recordCrash($sessionId, $reason);
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

        // Archipelago auto_shutdown after inactivity: the AP server stopped itself and the
        // orchestrateur retained the session volume (which holds the .apsave). Mark the session
        // idle and resumable. There is no MinIO save key under this model — the orchestrateur
        // relaunches from the volume — so a non-empty marker is stored to satisfy the resume gate.
        if ('session.idle' === $event) {
            $this->sessionLifecycleManager->recordPaused($sessionId, self::VOLUME_SAVE_MARKER, false);

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
