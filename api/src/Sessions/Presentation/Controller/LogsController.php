<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Exception\SessionLogsUnavailableException;
use App\Sessions\Application\Query\SessionContainerLogsQuery;
use App\Sessions\Application\Support\SessionContainerLogs;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The logs of a session's container (the Archipelago server, the bridge or the generation), read through
 * the orchestrator (story 17.33).
 */
final readonly class LogsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionContainerLogsQuery $logsQuery,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/logs', methods: ['GET'])]
    public function logs(Request $request, string $id): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $container = $request->query->get('container', 'ap');
        if (!in_array($container, SessionContainerLogs::CONTAINERS, true)) {
            return $this->apiAccessGuard->errorResponse('invalid_container', 'Conteneur inconnu (ap, bridge ou gen).', 400);
        }

        try {
            $logs = $this->logsQuery->forSession($id, $container);
        } catch (SessionLogsUnavailableException) {
            return $this->apiAccessGuard->errorResponse('orchestrator_unavailable', "L'orchestrateur ne donne pas les logs.", 503);
        }
        if (null === $logs) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse([
            'data' => [
                'container' => $logs->container,
                'found' => $logs->found,
                'logs' => $logs->logs,
                'fetched_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
