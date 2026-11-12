<?php

declare(strict_types=1);

namespace App\Streaming\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Streaming\Application\Command\ReportLocalStreams;
use App\Streaming\Application\Service\AdminLocalStreams;
use App\Streaming\Application\Service\MediaAgentAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 47.6: the régie agent reports the streams online on the LAN media server; an admin reads its secret. */
final readonly class LocalStreamsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ReportLocalStreams $report,
        private MediaAgentAccess $agentAccess,
        private AdminLocalStreams $adminStreams,
    ) {
    }

    /** Called by `ops/lan-media/agent.py`, signed with the event's agent secret. */
    #[Route('/api/v1/events/{eventId}/local-streams', name: 'api_events_local_streams_report', methods: ['PUT'])]
    public function report(Request $request, string $eventId): JsonResponse
    {
        $result = $this->report->report(
            $eventId,
            $request->headers->get('X-Archilan-Timestamp', ''),
            $request->headers->get('X-Archilan-Signature', ''),
            $request->getContent(),
        );

        return new JsonResponse(['data' => ['live' => $result->live, 'ignored' => $result->ignored, 'kick' => $result->kick]]);
    }

    #[Route('/api/v1/admin/events/{eventId}/media-agent', name: 'api_admin_events_media_agent', methods: ['GET'])]
    public function agent(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->agentAccess->forAdmin($eventId), 'meta' => []]);
    }

    /** Story 47.9: whether the régie agent runs, and who is on air from the LAN. */
    #[Route('/api/v1/admin/events/{eventId}/local-streams', name: 'api_admin_events_local_streams', methods: ['GET'])]
    public function state(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->adminStreams->forEvent($eventId), 'meta' => []]);
    }
}
