<?php

declare(strict_types=1);

namespace App\Content\Presentation\Controller;

use App\Content\Application\Command\ManagePartners;
use App\Content\Application\Command\PartnerDraft;
use App\Content\Application\Query\PartnerCatalogQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 45.1: the partners of the association, and their admin. */
final readonly class PartnerController
{
    use RequiresAuthTrait;

    /** No SVG: a logo lives on the public bucket, and an SVG can carry script. The MIME type is read from the content. */
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private PartnerCatalogQuery $query,
        private ManagePartners $manage,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    /** Public: the visible partners, in display order. */
    #[Route('/api/v1/partners', name: 'api_partners', methods: ['GET'])]
    public function forVisitors(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->forVisitors(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/partners', name: 'api_admin_partners', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forAdmin(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/partners', name: 'api_admin_partners_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof PartnerDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        // Invalid fields: typed failures, mapped to HTTP by the epic-35 listener.
        return new JsonResponse(['data' => ['id' => $this->manage->create($draft)], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/partners/reorder', name: 'api_admin_partners_reorder', methods: ['POST'])]
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

    #[Route('/api/v1/admin/partners/{id}', name: 'api_admin_partners_edit', methods: ['PUT'])]
    public function edit(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof PartnerDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->manage->edit($id, $draft);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/partners/{id}', name: 'api_admin_partners_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->delete($id);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/partners/{id}/logo', name: 'api_admin_partners_logo', methods: ['POST'])]
    public function uploadLogo(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('missing_file', 'Aucun fichier fourni.', 422);
        }
        if (!$file->isValid() || $file->getSize() > self::MAX_SIZE_BYTES) {
            $tooLarge = \UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError() || $file->getSize() > self::MAX_SIZE_BYTES;

            return $tooLarge
                ? $this->apiAccessGuard->errorResponse('image_too_large', 'Le logo ne peut pas dépasser 2 Mo.', 422)
                : $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }
        $extension = self::ALLOWED_MIMES[$file->getMimeType() ?? ''] ?? null;
        if (null === $extension) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez PNG, JPEG ou WebP (un SVG doit être converti).', 422);
        }

        $this->manage->attachLogo($id, $extension, (string) file_get_contents((string) $file->getRealPath()));

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/partners/{id}/logo', name: 'api_admin_partners_logo_remove', methods: ['DELETE'])]
    public function removeLogo(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->removeLogo($id);

        return new JsonResponse(null, 204);
    }

    private function draft(Request $request): ?PartnerDraft
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new PartnerDraft($text('name'), $text('description'), $text('url'), true === ($payload['active'] ?? false));
    }
}
