<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\Message\FetchLogsJob;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/logs', methods: ['GET'])]
    public function logs(Request $request, string $id): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $containerExists = in_array($session->getStatus(), [
            Session::STATUS_RUNNING,
            Session::STATUS_CRASHED,
        ], true);

        if ($containerExists) {
            $this->messageBus->dispatch(new FetchLogsJob($id));
        }

        return new JsonResponse([
            'data' => [
                'logs' => $session->getLastLogs() ?? '',
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
