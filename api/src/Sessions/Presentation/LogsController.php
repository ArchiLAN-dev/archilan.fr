<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\Message\FetchLogsJob;
use App\Sessions\Application\SessionQuery;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/logs', methods: ['GET'])]
    public function logs(Request $request, string $id): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->sessionQuery->findById($id);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $containerExists = in_array($session['status'], [
            Session::STATUS_RUNNING,
            Session::STATUS_CRASHED,
        ], true);

        if ($containerExists) {
            $this->messageBus->dispatch(new FetchLogsJob($id));
        }

        return new JsonResponse([
            'data' => [
                'logs' => $session['lastLogs'] ?? '',
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
