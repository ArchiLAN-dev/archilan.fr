<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\ModerationService;
use App\Community\Application\ReportQueryFilters;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminModerationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ModerationService $moderation,
    ) {
    }

    #[Route('/api/v1/admin/community/reports', name: 'api_admin_community_reports', methods: ['GET'])]
    public function reports(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $filters = ReportQueryFilters::fromRaw(
            $this->queryString($request, 'status'),
            $this->queryString($request, 'commentState'),
            $this->queryString($request, 'targetType'),
            $this->queryString($request, 'sort'),
            $this->queryString($request, 'q'),
            $request->query->getInt('limit', 50),
        );

        $result = $this->moderation->list($filters);

        return new JsonResponse(['data' => $result['reports'], 'meta' => ['count' => $result['count']]]);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }

    #[Route('/api/v1/admin/community/reports/{id}/resolve', name: 'api_admin_community_report_resolve', methods: ['POST'])]
    public function resolve(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->moderation->resolveReport($id, $admin->getId()), 'Signalement introuvable.');
    }

    #[Route('/api/v1/admin/community/comments/{id}/hide', name: 'api_admin_community_comment_hide', methods: ['POST'])]
    public function hide(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->moderation->hideComment($id), 'Commentaire introuvable.');
    }

    #[Route('/api/v1/admin/community/comments/{id}/restore', name: 'api_admin_community_comment_restore', methods: ['POST'])]
    public function restore(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->moderation->restoreComment($id), 'Commentaire introuvable.');
    }

    private function respond(string $result, string $notFoundMessage): JsonResponse
    {
        return 'ok' === $result
            ? new JsonResponse(null, 204)
            : $this->apiAccessGuard->errorResponse('not_found', $notFoundMessage, 404);
    }
}
