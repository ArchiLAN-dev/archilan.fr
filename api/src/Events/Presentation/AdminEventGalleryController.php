<?php

declare(strict_types=1);

namespace App\Events\Presentation;

use App\Events\Application\AdminEventDrafts;
use App\Events\Domain\Event;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminEventGalleryController
{
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 10 * 1024 * 1024;
    private const int MAX_GALLERY_SIZE = 12;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private AdminEventDrafts $adminEventDrafts,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/gallery', methods: ['POST'])]
    public function upload(Request $request, string $eventId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $event = $this->entityManager->find(Event::class, $eventId);
        if (!$event instanceof Event) {
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'Événement introuvable.']],
                404,
            );
        }

        if ($event->getPhotoGalleryCount() >= self::MAX_GALLERY_SIZE) {
            return new JsonResponse(
                ['error' => ['code' => 'gallery_full', 'message' => 'La galerie est pleine (max 12 photos).']],
                422,
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
        $uuid = bin2hex(random_bytes(16));
        $key = sprintf('events/%s/gallery/%s.%s', $eventId, $uuid, $ext);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => ['code' => 'storage_unavailable', 'message' => 'Le stockage est indisponible.']],
                503,
            );
        }

        $event->appendGalleryUpload($key);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->adminEventDrafts->get($eventId)]);
    }

    #[Route('/api/v1/admin/events/{eventId}/gallery/{index}', methods: ['DELETE'], requirements: ['index' => '\d+'])]
    public function delete(Request $request, string $eventId, int $index): Response
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $event = $this->entityManager->find(Event::class, $eventId);
        if (!$event instanceof Event) {
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'Événement introuvable.']],
                404,
            );
        }

        if (!$event->removeGalleryItem($index)) {
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'Index de galerie invalide.']],
                404,
            );
        }

        $this->entityManager->flush();

        return new Response(null, 204);
    }
}
