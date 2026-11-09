<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\PasseurJournalQuery;
use App\Sessions\Application\Query\PasseurOpeningRow;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 52.5: the Passeur's journal - an event's and a session's for the staff, a private run's for its owner. */
final readonly class PasseurJournalController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PasseurJournalQuery $journal,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/passeur-openings', name: 'api_admin_event_passeur_openings', methods: ['GET'])]
    public function event(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return self::rows($this->journal->forEvent($eventId));
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/passeur-openings', name: 'api_admin_session_passeur_openings', methods: ['GET'])]
    public function session(Request $request, string $sessionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return self::rows($this->journal->forSession($sessionId));
    }

    #[Route('/api/v1/runs/{runId}/passeur-openings', name: 'api_runs_passeur_openings', methods: ['GET'])]
    public function run(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return self::rows($this->journal->forRun($runId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true)));
    }

    /**
     * @param list<PasseurOpeningRow> $rows
     */
    private static function rows(array $rows): JsonResponse
    {
        return new JsonResponse(['data' => array_map(static fn (PasseurOpeningRow $row): array => $row->toArray(), $rows)]);
    }
}
