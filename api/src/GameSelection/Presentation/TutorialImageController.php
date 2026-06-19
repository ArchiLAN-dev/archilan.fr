<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\UploadTutorialImageCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Upload endpoint for tutorial-step images (story 31.10). Authenticated-user gated like the contribution
 * submit endpoint, so members can attach screenshots to their submissions (which stay moderated).
 */
final readonly class TutorialImageController
{
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private UploadTutorialImageCommand $command,
    ) {
    }

    #[Route('/api/v1/tutorial-images', name: 'api_tutorial_images_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('missing_file', 'Aucun fichier fourni.', 422);
        }

        if (!$file->isValid()) {
            $uploadError = $file->getError();
            if (\UPLOAD_ERR_INI_SIZE === $uploadError || \UPLOAD_ERR_FORM_SIZE === $uploadError) {
                return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 10 Mo.", 422);
            }

            return $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 10 Mo.", 422);
        }

        $mime = $file->getMimeType() ?? '';
        if (!array_key_exists($mime, self::ALLOWED_MIMES)) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG, WebP ou GIF.', 422);
        }

        $key = sprintf('tutorials/%s.%s', bin2hex(random_bytes(16)), self::ALLOWED_MIMES[$mime]);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        try {
            $data = $this->command->execute($key, $contents);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('storage_unavailable', 'Le stockage est indisponible.', 503);
        }

        return new JsonResponse(['data' => $data]);
    }
}
