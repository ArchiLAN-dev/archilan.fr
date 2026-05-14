<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ApworldDownloadUrlController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private MinioStorageInterface $minioStorage,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/apworlds/{sha256}/download-url', methods: ['GET'])]
    public function __invoke(Request $request, string $sessionId, string $sha256): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $game = $this->entityManager->getRepository(ArchipelagoGame::class)
            ->findOneBy(['apworldHash' => $sha256]);

        if (!$game instanceof ArchipelagoGame || null === $game->getApworldMinioKey()) {
            return new JsonResponse(
                ['error' => ['code' => 'apworld_not_in_minio', 'message' => 'APWorld not found in object storage.']],
                404,
            );
        }

        try {
            $url = $this->minioStorage->presignedUrl(
                $this->minioApworldsBucket,
                $game->getApworldMinioKey(),
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
