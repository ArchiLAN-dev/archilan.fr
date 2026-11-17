<?php

declare(strict_types=1);

namespace App\Content\Presentation\Controller;

use App\Content\Application\Command\ManageTrackedLinks;
use App\Content\Application\Command\RecordLinkVisit;
use App\Content\Application\Command\TrackedLinkDraft;
use App\Content\Application\Query\TrackedLinkCatalogQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 55.1: the tracked links `/l/{code}`, their admin, and the visit the site's redirect records. */
final readonly class TrackedLinkController
{
    use RequiresAuthTrait;

    public function __construct(
        private TrackedLinkCatalogQuery $query,
        private ManageTrackedLinks $manage,
        private RecordLinkVisit $recordVisit,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    /**
     * Public: called by the site's `/l/{code}` route, which decides whether the pass counts (no robot, no prefetch)
     * and gives the host of the page the visitor came from. Answers the target; an unknown or turned off link is a 404.
     */
    #[Route('/api/v1/links/{code}/visit', name: 'api_links_visit', methods: ['POST'])]
    public function visit(Request $request, string $code): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $host = is_string($payload['referrerHost'] ?? null) ? $payload['referrerHost'] : '';

        $target = $this->recordVisit->visit($code, $host, false !== ($payload['count'] ?? true));

        return new JsonResponse(['data' => ['target' => $target->target], 'meta' => []]);
    }

    #[Route('/api/v1/admin/links', name: 'api_admin_links', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forAdmin(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/links', name: 'api_admin_links_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof TrackedLinkDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        return new JsonResponse(['data' => ['id' => $this->manage->create($draft)], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/links/{id}', name: 'api_admin_links_edit', methods: ['PUT'])]
    public function edit(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof TrackedLinkDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->manage->edit($id, $draft);

        return new JsonResponse(null, 204);
    }

    private function draft(Request $request): ?TrackedLinkDraft
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new TrackedLinkDraft($text('code'), $text('label'), $text('target'), true === ($payload['active'] ?? false));
    }
}
