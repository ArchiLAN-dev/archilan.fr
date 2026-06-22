<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\AchievementImageService;
use App\Community\Application\AdminAchievementService;
use App\Community\Domain\InvalidAchievementRuleException;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for configurable achievement definitions (story 30.16). Deserialize → validate via the
 * application service → serialize; 422 on a malformed key or rule tree.
 */
final readonly class AdminAchievementController
{
    use RequiresAuthTrait;

    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminAchievementService $achievements,
        private AchievementImageService $images,
    ) {
    }

    #[Route('/api/v1/admin/community/achievements', name: 'api_admin_community_achievements', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $dashboard = $this->achievements->dashboard();

        return new JsonResponse(['data' => $dashboard['definitions'], 'meta' => ['options' => $dashboard['options']]]);
    }

    #[Route('/api/v1/admin/community/achievements', name: 'api_admin_community_achievements_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            return new JsonResponse(['data' => $this->achievements->create($this->jsonPayload($request))], 201);
        } catch (InvalidAchievementRuleException|\InvalidArgumentException $e) {
            return $this->invalid($e);
        }
    }

    #[Route('/api/v1/admin/community/achievements/{id}', name: 'api_admin_community_achievements_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            $result = $this->achievements->update($id, $this->jsonPayload($request));
        } catch (InvalidAchievementRuleException|\InvalidArgumentException $e) {
            return $this->invalid($e);
        }

        return null === $result
            ? $this->apiAccessGuard->errorResponse('not_found', 'Succès introuvable.', 404)
            : new JsonResponse(['data' => $result]);
    }

    #[Route('/api/v1/admin/community/achievements/image', name: 'api_admin_community_achievements_image', methods: ['POST'])]
    public function uploadImage(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('missing_file', 'Aucun fichier fourni.', 422);
        }

        if (!$file->isValid()) {
            $uploadError = $file->getError();
            if (\UPLOAD_ERR_INI_SIZE === $uploadError || \UPLOAD_ERR_FORM_SIZE === $uploadError) {
                return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 5 Mo.", 422);
            }

            return $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 5 Mo.", 422);
        }

        $mime = $file->getMimeType() ?? '';
        if (!array_key_exists($mime, self::ALLOWED_MIMES)) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG ou WebP.', 422);
        }

        $key = sprintf('community/achievement-images/%s.%s', bin2hex(random_bytes(16)), self::ALLOWED_MIMES[$mime]);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        try {
            $url = $this->images->upload($key, $contents);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('storage_unavailable', 'Le stockage est indisponible.', 503);
        }

        return new JsonResponse(['data' => ['key' => $key, 'imageUrl' => $url]]);
    }

    #[Route('/api/v1/admin/community/achievements/{id}/active', name: 'api_admin_community_achievements_active', methods: ['POST'])]
    public function setActive(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $active = (bool) ($this->jsonPayload($request)['active'] ?? false);

        return $this->achievements->setActive($id, $active)
            ? new JsonResponse(null, 204)
            : $this->apiAccessGuard->errorResponse('not_found', 'Succès introuvable.', 404);
    }

    #[Route('/api/v1/admin/community/achievements/reorder', name: 'api_admin_community_achievements_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $rawIds = $this->jsonPayload($request)['ids'] ?? null;
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        $this->achievements->reorder($ids);

        return new JsonResponse(null, 204);
    }

    private function invalid(\Throwable $e): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
