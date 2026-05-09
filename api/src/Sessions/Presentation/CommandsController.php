<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CommandsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/commands', methods: ['POST'])]
    public function commands(Request $request, string $id): JsonResponse
    {
        $user = $this->apiAccessGuard->requireAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = $this->jsonBody($request);
        $command = is_string($body['command'] ?? null) ? trim($body['command']) : '';
        if ('' === $command) {
            return $this->apiAccessGuard->errorResponse('invalid_command', 'La commande est requise.', 422);
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $this->httpClient->request(
                'POST',
                sprintf('http://%s:%d/commands', $host, $bridgePort),
                ['json' => ['command' => $command], 'timeout' => 3],
            );
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $id,
            $user->getId(),
            'command',
            ['command' => $command],
            new \DateTimeImmutable(),
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['ok' => true]]);
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
