<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ForceEndController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/force-end', methods: ['POST'])]
    public function forceEnd(Request $request, string $id): JsonResponse
    {
        $user = $this->apiAccessGuard->requireAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $now = new \DateTimeImmutable();
        $port = $session->getPort() ?? 0;
        $bridgePort = $session->getBridgePort() ?? 0;

        $session->transition(Session::STATUS_FINISHED, $now);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new StopRunJob($id, $port, $bridgePort));
        $this->messageBus->dispatch(new ArchiveRunJob($id, $bridgePort));

        $log = new RunAuditLog(
            bin2hex(random_bytes(16)),
            $id,
            $user->getId(),
            'force_end',
            null,
            $now,
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $session->payload()]);
    }
}
