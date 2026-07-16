<?php

declare(strict_types=1);

namespace App\Events\Presentation\Controller;

use App\Events\Application\Command\ManageEventGalleryCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminEventGalleryController
{
    use RequiresAuthTrait;
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ManageEventGalleryCommand $manageEventGalleryCommand,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/gallery', methods: ['POST'])]
    public function upload(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(
                ['error' => ['code' => 'missing_file', 'message' => 'Aucun fichier fourni.']],
                422,
            );
        }

        $mime = $file->getMimeType() ?? '';
        if (!array_key_exists($mime, self::ALLOWED_MIMES)) {
            return new JsonResponse(
                ['error' => ['code' => 'image_invalid_type', 'message' => 'Type de fichier non supporté. Utilisez JPEG, PNG ou WebP.']],
                422,
            );
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return new JsonResponse(
                ['error' => ['code' => 'image_too_large', 'message' => "L'image ne peut pas dépasser 10 Mo."]],
                422,
            );
        }

        $ext = self::ALLOWED_MIMES[$mime];
        $uuid = bin2hex(random_bytes(16));
        $key = sprintf('events/%s/gallery/%s.%s', $eventId, $uuid, $ext);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        // Failures (event missing, gallery full, storage down) are thrown as typed ApplicationFailures
        // and mapped to HTTP by ApplicationFailureListener (epic 35).
        $data = $this->manageEventGalleryCommand->upload($eventId, $key, $contents);

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/v1/admin/events/{eventId}/gallery/{index}', methods: ['DELETE'], requirements: ['index' => '\d+'])]
    public function delete(Request $request, string $eventId, int $index): Response
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $this->manageEventGalleryCommand->delete($eventId, $index);

        return new Response(null, 204);
    }
}
