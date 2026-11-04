<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\UploadTutorialImageCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Psr\Log\LoggerInterface;
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

    /** Story 50.3: ample for a full-page 4K capture, and what keeps a light file from unfolding into a huge image. */
    private const int MAX_PIXELS = 40_000_000;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private UploadTutorialImageCommand $command,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/tutorial-images', name: 'api_tutorial_images_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
        if (!$isAdmin && !$user->isEmailVerified()) {
            return $this->apiAccessGuard->errorResponse('email_not_verified', 'Confirme ton adresse email pour envoyer des images.', 403);
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

        $contents = (string) file_get_contents((string) $file->getRealPath());

        $dimensions = @getimagesizefromstring($contents);
        if (false === $dimensions) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG, WebP ou GIF.', 422);
        }
        if ($dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            return $this->apiAccessGuard->errorResponse('image_too_many_pixels', "Les dimensions de l'image sont trop grandes.", 422);
        }

        $key = sprintf('tutorials/%s.%s', bin2hex(random_bytes(16)), self::ALLOWED_MIMES[$mime]);

        try {
            $data = $this->command->execute($key, $contents, $user->getId(), $isAdmin);
        } catch (\Throwable $exception) {
            // The client only ever sees a generic storage_unavailable; log the real cause here or it is
            // lost (e.g. a missing/misconfigured media bucket).
            $this->logger->error('Tutorial image upload to object storage failed.', [
                'key' => $key,
                'exception' => $exception,
            ]);

            return $this->apiAccessGuard->errorResponse('storage_unavailable', 'Le stockage est indisponible.', 503);
        }

        if (null === $data) {
            return $this->apiAccessGuard->errorResponse('too_many_requests', "Trop d'images envoyées. Réessaie plus tard.", 429);
        }

        // Stable URL for the markdown description: a presigned one would expire once written into
        // content (story 31.11). Built from the request so each environment serves its own host.
        $stableUrl = sprintf(
            '%s/api/v1/tutorial-images/%s',
            rtrim($request->getSchemeAndHttpHost(), '/'),
            basename($data->key),
        );

        return new JsonResponse(['data' => ['key' => $data->key, 'url' => $stableUrl]]);
    }
}
