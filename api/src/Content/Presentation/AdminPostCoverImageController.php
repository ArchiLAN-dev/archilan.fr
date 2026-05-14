<?php

declare(strict_types=1);

namespace App\Content\Presentation;

use App\Content\Application\AdminPostCatalog;
use App\Content\Domain\Post;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminPostCoverImageController
{
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private AdminPostCatalog $adminPostCatalog,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
    ) {
    }

    #[Route('/api/v1/admin/posts/{postId}/cover-image', methods: ['POST'])]
    public function __invoke(Request $request, string $postId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $post = $this->entityManager->find(Post::class, $postId);
        if (!$post instanceof Post) {
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'Article introuvable.']],
                404,
            );
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

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => ['code' => 'storage_unavailable', 'message' => 'Le stockage est indisponible.']],
                503,
            );
        }

        $post->setCoverImageKey($key, new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->adminPostCatalog->get($postId)]);
    }
}
