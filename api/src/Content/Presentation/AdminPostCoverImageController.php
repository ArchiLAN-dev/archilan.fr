<?php

declare(strict_types=1);

namespace App\Content\Presentation;

use App\Content\Application\UploadPostCoverImageCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminPostCoverImageController
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
        private UploadPostCoverImageCommand $uploadPostCoverImageCommand,
    ) {
    }

    #[Route('/api/v1/admin/posts/{postId}/cover-image', methods: ['POST'])]
    public function __invoke(Request $request, string $postId): JsonResponse
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
        $key = sprintf('posts/%s/cover.%s', $postId, $ext);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        $result = $this->uploadPostCoverImageCommand->execute($postId, $key, $contents);

        if ('not_found' === $result['outcome']) {
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'Article introuvable.']],
                404,
            );
        }

        if ('storage_error' === $result['outcome']) {
            return new JsonResponse(
                ['error' => ['code' => 'storage_unavailable', 'message' => 'Le stockage est indisponible.']],
                503,
            );
        }

        return new JsonResponse(['data' => $result['data']]);
    }
}
