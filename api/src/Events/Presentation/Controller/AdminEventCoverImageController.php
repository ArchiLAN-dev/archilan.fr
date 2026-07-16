<?php

declare(strict_types=1);

namespace App\Events\Presentation\Controller;

use App\Events\Application\Command\UploadEventCoverImageCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminEventCoverImageController
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
        private UploadEventCoverImageCommand $uploadEventCoverImageCommand,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/cover-image', methods: ['POST'])]
    public function __invoke(Request $request, string $eventId): JsonResponse
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

        if (!$file->isValid()) {
            $uploadError = $file->getError();
            if (\UPLOAD_ERR_INI_SIZE === $uploadError || \UPLOAD_ERR_FORM_SIZE === $uploadError) {
                return new JsonResponse(
                    ['error' => ['code' => 'image_too_large', 'message' => "L'image ne peut pas dépasser 10 Mo."]],
                    422,
                );
            }

            return new JsonResponse(
                ['error' => ['code' => 'upload_error', 'message' => 'Le fichier uploadé est invalide.']],
                422,
            );
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return new JsonResponse(
                ['error' => ['code' => 'image_too_large', 'message' => "L'image ne peut pas dépasser 10 Mo."]],
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

        $ext = self::ALLOWED_MIMES[$mime];
        $key = sprintf('events/%s/cover.%s', $eventId, $ext);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        // Failures (event missing, storage down) are thrown as typed ApplicationFailures and mapped to
        // HTTP by ApplicationFailureListener (epic 35).
        $data = $this->uploadEventCoverImageCommand->execute($eventId, $key, $contents);

        return new JsonResponse(['data' => $data]);
    }
}
