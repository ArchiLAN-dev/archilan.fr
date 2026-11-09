<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Command\DismissPasseurNotice;
use App\Sessions\Application\Query\PasseurNoticeQuery;
use App\Sessions\Application\Query\PasseurOpeningRow;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 52.5: « Pendant ton absence, le Passeur a ouvert... » - the viewer's own notice for a session. It only ever
 * lists the viewer's world: another player gets an empty one, never someone else's.
 */
final readonly class PasseurNoticeController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PasseurNoticeQuery $notice,
        private DismissPasseurNotice $dismiss,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/passeur/notice', name: 'api_sessions_passeur_notice', methods: ['GET'])]
    public function show(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $notice = $this->notice->forAbsent($sessionId, $user->getId());

        return new JsonResponse(['data' => [
            'openings' => array_map(static fn (PasseurOpeningRow $row): array => $row->toArray(), $notice->openings),
            'total' => $notice->total,
            'kind' => $notice->kind,
        ]]);
    }

    #[Route('/api/v1/sessions/{sessionId}/passeur/notice', name: 'api_sessions_passeur_notice_dismiss', methods: ['DELETE'])]
    public function dismiss(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->dismiss->execute($sessionId, $user->getId());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
