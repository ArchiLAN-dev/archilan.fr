<?php

declare(strict_types=1);

namespace App\Content\Presentation\Controller;

use App\Content\Application\Command\HelpGuideDraft;
use App\Content\Application\Command\ManageHelpGuides;
use App\Content\Application\Query\HelpGuideCatalogQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 53.5: the help guides, and their admin. */
final readonly class HelpGuideController
{
    use RequiresAuthTrait;

    public function __construct(
        private HelpGuideCatalogQuery $query,
        private ManageHelpGuides $manage,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    /** Public: the published guides, in display order, as cards. */
    #[Route('/api/v1/help-guides', name: 'api_help_guides', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->published(), 'meta' => []]);
    }

    /** Public: one published guide; a draft or an unknown slug is a 404. */
    #[Route('/api/v1/help-guides/{slug}', name: 'api_help_guide', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->publishedBySlug($slug), 'meta' => []]);
    }

    #[Route('/api/v1/admin/help-guides', name: 'api_admin_help_guides', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forAdmin(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/help-guides', name: 'api_admin_help_guides_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof HelpGuideDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        // Invalid fields and a taken slug: typed failures, mapped to HTTP by the epic-35 listener.
        return new JsonResponse(['data' => ['id' => $this->manage->create($draft)], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/help-guides/reorder', name: 'api_admin_help_guides_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = json_decode($request->getContent(), true);
        $rawIds = is_array($payload) ? ($payload['ids'] ?? null) : null;
        if (!is_array($rawIds)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $ids = array_values(array_filter($rawIds, is_string(...)));

        $this->manage->reorder($ids);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/help-guides/{id}', name: 'api_admin_help_guides_edit', methods: ['PUT'])]
    public function edit(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof HelpGuideDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->manage->edit($id, $draft);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/help-guides/{id}', name: 'api_admin_help_guides_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->delete($id);

        return new JsonResponse(null, 204);
    }

    private function draft(Request $request): ?HelpGuideDraft
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new HelpGuideDraft($text('slug'), $text('title'), $text('summary'), $text('body'), true === ($payload['published'] ?? false));
    }
}
