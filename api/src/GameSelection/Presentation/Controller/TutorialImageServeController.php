<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves an uploaded tutorial image under a **stable** URL (story 31.11).
 *
 * Tutorial images used to be reachable only through a presigned URL re-derived at read time. That is
 * fine while the key lives in a dedicated field, but images now sit inside the markdown description as
 * `![](url)` - a URL written into content must never expire, so it cannot be a presigned one.
 *
 * Public on purpose: these images render on public game pages, so requiring auth would break them for
 * visitors. Two things keep that safe:
 *  - the filename is validated against the exact shape the uploader generates (32 random hex bytes plus
 *    an allow-listed extension), so this endpoint can only ever reach `tutorials/…` objects and never
 *    another part of the media bucket (apworlds, spoilers, …);
 *  - those 128 random bits make a key unguessable.
 */
final readonly class TutorialImageServeController
{
    /** Mirrors the extensions {@see TutorialImageController} is willing to store. */
    private const string FILENAME_PATTERN = '[0-9a-f]{32}\.(?:jpg|png|webp|gif)';

    private const array CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
    ) {
    }

    #[Route(
        '/api/v1/tutorial-images/{filename}',
        name: 'api_tutorial_images_serve',
        requirements: ['filename' => self::FILENAME_PATTERN],
        methods: ['GET'],
    )]
    public function __invoke(string $filename): Response
    {
        // Belt and braces: the route requirement already constrains this, but the key is built here
        // rather than taken from the URL so no caller-controlled path can reach the storage layer.
        if (1 !== preg_match('/^'.self::FILENAME_PATTERN.'$/', $filename)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Image introuvable.', 404);
        }

        $key = 'tutorials/'.$filename;

        try {
            $contents = $this->minioStorage->download($this->minioMediaBucket, $key);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Image introuvable.', 404);
        }

        $extension = pathinfo($filename, \PATHINFO_EXTENSION);

        $response = new Response($contents, 200, [
            'Content-Type' => self::CONTENT_TYPES[$extension] ?? 'application/octet-stream',
            // Keys are random and never reused, so the object at a given URL never changes.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
