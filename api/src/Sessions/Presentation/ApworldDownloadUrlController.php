<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\ApworldQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Infrastructure\MinioStorageInterface;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ApworldDownloadUrlController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ApworldQuery $apworldQuery,
        private MinioStorageInterface $minioStorage,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/apworlds/{sha256}/download-url', methods: ['GET'])]
    public function __invoke(Request $request, string $sessionId, string $sha256): JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $minioKey = $this->apworldQuery->findApworldMinioKey($sha256);

        if (null === $minioKey) {
            return new JsonResponse(
                ['error' => ['code' => 'apworld_not_in_minio', 'message' => 'APWorld not found in object storage.']],
                404,
            );
        }

        try {
            $url = $this->minioStorage->presignedUrl(
                $this->minioApworldsBucket,
                $minioKey,
                $this->minioPresignTtl,
            );
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => ['code' => 'storage_unavailable', 'message' => 'Object storage is unavailable.']],
                503,
            );
        }

        return new JsonResponse(['data' => ['url' => $url, 'expiresIn' => $this->minioPresignTtl]]);
    }
}
