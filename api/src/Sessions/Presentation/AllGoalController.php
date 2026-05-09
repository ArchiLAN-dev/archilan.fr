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

final readonly class AllGoalController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{id}/all-goal', methods: ['POST'])]
    public function allGoal(Request $request, string $id): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret runner invalide.', 401);
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return new JsonResponse(['data' => ['ok' => true, 'skipped' => true]]);
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
            'bridge-system',
            'all_goal',
            null,
            $now,
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['ok' => true, 'skipped' => false]]);
    }
}
