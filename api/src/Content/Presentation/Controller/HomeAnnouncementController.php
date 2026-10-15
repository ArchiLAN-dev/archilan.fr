<?php

declare(strict_types=1);

namespace App\Content\Presentation\Controller;

use App\Content\Application\Command\HomeAnnouncementDraft;
use App\Content\Application\Command\ManageHomeAnnouncements;
use App\Content\Application\Query\HomeAnnouncementQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 10.14: the announcement opened as a modal on the home page, and its admin. */
final readonly class HomeAnnouncementController
{
    use RequiresAuthTrait;

    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private HomeAnnouncementQuery $query,
        private ManageHomeAnnouncements $manage,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    /** Public: the running announcement, or the next one starting within a day, null otherwise. */
    #[Route('/api/v1/home-announcement', name: 'api_home_announcement', methods: ['GET'])]
    public function forVisitors(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->forVisitors(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/announcements', name: 'api_admin_home_announcements', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forAdmin(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/announcements', name: 'api_admin_home_announcements_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof HomeAnnouncementDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        // Invalid fields or an overlapping window: typed failures, mapped to HTTP by the epic-35 listener.
        return new JsonResponse(['data' => ['id' => $this->manage->create($draft)], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/announcements/{id}', name: 'api_admin_home_announcements_edit', methods: ['PUT'])]
    public function edit(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($request);
        if (!$draft instanceof HomeAnnouncementDraft) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->manage->edit($id, $draft);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/announcements/{id}', name: 'api_admin_home_announcements_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->delete($id);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/announcements/{id}/image', name: 'api_admin_home_announcements_image', methods: ['POST'])]
    public function uploadImage(Request $request, string $id): JsonResponse
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
                ? $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 10 Mo.", 422)
                : $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }
        $extension = self::ALLOWED_MIMES[$file->getMimeType() ?? ''] ?? null;
        if (null === $extension) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG ou WebP.', 422);
        }

        $this->manage->attachImage($id, $extension, (string) file_get_contents((string) $file->getRealPath()));

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/announcements/{id}/image', name: 'api_admin_home_announcements_image_remove', methods: ['DELETE'])]
    public function removeImage(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->removeImage($id);

        return new JsonResponse(null, 204);
    }

    private function draft(Request $request): ?HomeAnnouncementDraft
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new HomeAnnouncementDraft(
            $text('title'),
            $text('body'),
            $text('ctaLabel'),
            $text('ctaUrl'),
            self::date($payload['startsAt'] ?? null),
            self::date($payload['endsAt'] ?? null),
        );
    }

    /** An ISO 8601 date with its offset, with or without milliseconds (`toISOString()` in a browser). */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        foreach ([\DATE_ATOM, 'Y-m-d\TH:i:s.vP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
