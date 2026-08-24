<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\PatchDownloadUrl;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a generated output file from a signed link, with no caller (story 16.16).
 *
 * Deliberately the only route here without `requireAuthenticatedUser`: the authorization is the
 * signature, so that a player can send the link of their patch to someone who has no account. The
 * three authenticated routes it complements (private runs, event registrations, weekly runs) are
 * unchanged and remain the normal path for a signed-in player.
 *
 * Nothing is resolved from the caller, so nothing is enumerable: a request without a valid
 * signature is refused before the filename is even looked at, whether or not it exists.
 */
final readonly class PublicPatchDownloadController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PatchDownloadUrl $signedUrl,
        private SessionOutputArtifactReaderInterface $reader,
        private string $workspaceDir,
    ) {
    }

    #[Route('/api/v1/public/patches/{filename}', name: 'api_public_patch_download', methods: ['GET'])]
    public function download(Request $request, string $filename): Response
    {
        if (!$this->signedUrl->isValid($request)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Lien invalide.', 403);
        }

        // The emitters only ever sign files they listed, so these can only be reached by a link
        // minted before the rule existed. Kept as a second lock on the two files that must never
        // leave: the multidata seed and the spoiler.
        if (!self::isDownloadable($filename)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        $archive = $request->query->get('archive');
        if (is_string($archive) && '' !== $archive) {
            return $this->fromArchive($archive, $filename);
        }

        $sessionId = $request->query->get('workspace');
        if (is_string($sessionId) && '' !== $sessionId) {
            return $this->fromWorkspace($sessionId, $filename);
        }

        return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
    }

    /** A file held inside a session's durable output archive on object storage. */
    private function fromArchive(string $outputKey, string $filename): Response
    {
        $artifact = $this->reader->extractEntry($outputKey, $filename);
        if (null === $artifact) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $contents = $artifact->contents;
        $safeFilename = basename($artifact->filename);

        $streamed = new StreamedResponse(static function () use ($contents): void {
            echo $contents;
        });
        $streamed->headers->set('Content-Type', 'application/octet-stream');
        $streamed->headers->set('Content-Disposition', 'attachment; filename="'.$safeFilename.'"');

        return $streamed;
    }

    /**
     * A file sitting in a session's output directory on the shared workspace volume. The session id
     * is signed, but the containment check stays: a signature proves the link was minted here, not
     * that the path it carries resolves inside the workspace.
     */
    private function fromWorkspace(string $sessionId, string $filename): Response
    {
        $outputDir = realpath($this->workspaceDir.'/'.basename($sessionId).'/output');
        if (false === $outputDir) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $filePath = realpath($outputDir.'/'.basename($filename));
        if (false === $filePath || !str_starts_with($filePath, $outputDir.\DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($filename));

        return $response;
    }

    private static function isDownloadable(string $filename): bool
    {
        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return false;
        }

        return !str_contains(strtolower($filename), '_spoiler');
    }
}
