<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionExportQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ExportController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionExportQuery $sessionExportQuery,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/export', methods: ['GET'])]
    public function export(Request $request, string $id): Response
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $rows = $this->sessionExportQuery->findSlotsForSession($id);
        if (null === $rows) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $format = $request->query->get('format', 'json');

        if ('csv' === $format) {
            return $this->csvResponse($rows, $id);
        }

        return new JsonResponse(['data' => $rows]);
    }

    /**
     * @param list<array{slot_name: string, player: string, game: string, checks_done: int, items_received: int, goal_reached_at: string|null}> $rows
     */
    private function csvResponse(array $rows, string $sessionId): Response
    {
        $csv = "slot_name,player,game,checks_done,items_received,goal_reached_at\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%d,%d,%s\n",
                $this->csvEscape($row['slot_name']),
                $this->csvEscape($row['player']),
                $this->csvEscape($row['game']),
                $row['checks_done'],
                $row['items_received'],
                $this->csvEscape($row['goal_reached_at'] ?? ''),
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="session-'.$sessionId.'.csv"',
        ]);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
