<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionQuery;
use App\Shared\Infrastructure\DockerSocketClient;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ContainerController
{
    use RequiresAuthTrait;
    private const array ALLOWED_ACTIONS = ['start', 'stop', 'rm', 'restart', 'logs'];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private DockerSocketClient $docker,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/container', methods: ['GET'])]
    public function state(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $containerName = 'archipelago-run-'.$sessionId;

        try {
            $info = $this->docker->inspect($containerName);
            /** @var array<string, mixed> $st */
            $st = is_array($info['State'] ?? null) ? $info['State'] : [];

            return new JsonResponse([
                'data' => [
                    'container' => $containerName,
                    'found' => true,
                    'status' => is_string($st['Status'] ?? null) ? $st['Status'] : 'unknown',
                    'running' => (bool) ($st['Running'] ?? false),
                    'paused' => (bool) ($st['Paused'] ?? false),
                    'restarting' => (bool) ($st['Restarting'] ?? false),
                    'exit_code' => is_int($st['ExitCode'] ?? null) ? $st['ExitCode'] : null,
                    'error' => is_string($st['Error'] ?? null) ? $st['Error'] : '',
                    'started_at' => is_string($st['StartedAt'] ?? null) ? $st['StartedAt'] : null,
                    'finished_at' => is_string($st['FinishedAt'] ?? null) ? $st['FinishedAt'] : null,
                ],
            ]);
        } catch (\Throwable) {
            return new JsonResponse([
                'data' => [
                    'container' => $containerName,
                    'found' => false,
                    'status' => 'not_found',
                    'running' => false,
                ],
            ]);
        }
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/container', methods: ['POST'])]
    public function exec(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $body = $this->jsonBody($request);
        $action = is_string($body['action'] ?? null) ? $body['action'] : '';

        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return $this->apiAccessGuard->errorResponse('invalid_action', 'Action invalide.', 400);
        }

        $containerName = 'archipelago-run-'.$sessionId;

        try {
            if ('logs' === $action) {
                $output = $this->docker->tailLogs($containerName);
            } else {
                match ($action) {
                    'start' => $this->docker->start($containerName),
                    'stop' => $this->docker->stop($containerName),
                    'restart' => $this->docker->restart($containerName),
                    'rm' => $this->docker->remove($containerName, force: true),
                };
                $output = '';
            }

            return new JsonResponse([
                'data' => [
                    'action' => $action,
                    'container' => $containerName,
                    'success' => true,
                    'output' => $output,
                ],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'data' => [
                    'action' => $action,
                    'container' => $containerName,
                    'success' => false,
                    'output' => $e->getMessage(),
                ],
            ]);
        }
    }

    /** @return array<string, mixed> */
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
