<?php

declare(strict_types=1);

namespace App\Notes\Presentation\Controller;

use App\Notes\Application\Command\ManageAdminNotes;
use App\Notes\Application\Query\AdminNoteQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 46.1: the admin notes. Admins only, on every route: no member or public route ever returns a note.
 * Invalid input from the Application layer comes as typed failures, mapped to HTTP by the epic-35 listener.
 */
final readonly class AdminNoteController
{
    use RequiresAuthTrait;

    public function __construct(
        private ManageAdminNotes $manage,
        private AdminNoteQuery $query,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/notes', name: 'api_admin_notes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $notes = $this->query->forTarget($request->query->getString('targetType'), $request->query->getString('targetId'));

        return new JsonResponse(['data' => $notes, 'meta' => []]);
    }

    #[Route('/api/v1/admin/notes', name: 'api_admin_notes_write', methods: ['POST'])]
    public function write(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $targetType = $payload['targetType'] ?? null;
        $targetId = $payload['targetId'] ?? null;
        $body = $payload['body'] ?? null;
        if (!is_string($targetType) || !is_string($targetId) || !is_string($body)) {
            return $this->invalidJson();
        }

        $id = $this->manage->write($targetType, $targetId, $body, $admin->getId());

        return new JsonResponse(['data' => ['id' => $id], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/notes/open-counts', name: 'api_admin_notes_open_counts', methods: ['POST'])]
    public function openCounts(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $targetType = $payload['targetType'] ?? null;
        $targetIds = $payload['targetIds'] ?? null;
        if (!is_string($targetType)) {
            return $this->invalidJson();
        }
        if (!is_array($targetIds) || !array_is_list($targetIds) || array_filter($targetIds, is_string(...)) !== $targetIds) {
            return $this->apiAccessGuard->errorResponse('note_target_ids_invalid', 'Une liste d\'identifiants est attendue.', 422);
        }

        // An empty map encodes as an object, not a list: the client reads it by target id.
        return new JsonResponse(['data' => (object) $this->query->openCounts($targetType, $targetIds), 'meta' => []]);
    }

    #[Route('/api/v1/admin/notes/{noteId}', name: 'api_admin_notes_edit', methods: ['PATCH'])]
    public function edit(Request $request, string $noteId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $body = $this->payload($request)['body'] ?? null;
        if (!is_string($body)) {
            return $this->invalidJson();
        }

        $this->manage->edit($noteId, $body, $admin->getId());

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/notes/{noteId}/follow-ups', name: 'api_admin_notes_follow_up', methods: ['POST'])]
    public function followUp(Request $request, string $noteId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $body = $this->payload($request)['body'] ?? null;
        if (!is_string($body)) {
            return $this->invalidJson();
        }

        $id = $this->manage->followUp($noteId, $body, $admin->getId());

        return new JsonResponse(['data' => ['id' => $id], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/notes/{noteId}/settle', name: 'api_admin_notes_settle', methods: ['POST'])]
    public function settle(Request $request, string $noteId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $body = $this->optionalBody($request);
        if (null === $body) {
            return $this->invalidJson();
        }

        $this->manage->settle($noteId, $body, $admin->getId());

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/notes/{noteId}/reopen', name: 'api_admin_notes_reopen', methods: ['POST'])]
    public function reopen(Request $request, string $noteId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $body = $this->optionalBody($request);
        if (null === $body) {
            return $this->invalidJson();
        }

        $this->manage->reopen($noteId, $body, $admin->getId());

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/notes/{noteId}/revisions', name: 'api_admin_notes_revisions', methods: ['GET'])]
    public function revisions(Request $request, string $noteId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->revisions($noteId), 'meta' => []]);
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /** The text of a change of state is optional: no body, or an empty one, is an empty text; anything else but a string is refused. */
    private function optionalBody(Request $request): ?string
    {
        $body = '' === $request->getContent() ? null : ($this->payload($request)['body'] ?? null);
        if (null === $body) {
            return '';
        }

        return is_string($body) ? $body : null;
    }

    private function invalidJson(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
    }
}
