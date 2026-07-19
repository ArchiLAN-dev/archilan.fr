<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Controller;

use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\WeeklyRuns\Application\Query\WeeklyEntryPatchQuery;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyEntryPatchController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyEntryPatchQuery $patchQuery,
        private SessionOutputArtifactReaderInterface $reader,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/patches', methods: ['GET'])]
    public function list(Request $request, string $weeklyRunId, string $entryId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $context = $this->patchQuery->forEntry($weeklyRunId, $entryId, $user->getId());
        if (null === $context) {
            return new JsonResponse(['data' => ['files' => []]]);
        }

        if ('durable' === $context['type']) {
            $files = array_values(array_filter(
                $this->reader->listEntries($context['outputKey']),
                self::isDownloadablePatch(...),
            ));
            sort($files);

            return new JsonResponse(['data' => ['files' => $files]]);
        }

        $files = $this->findPatchFiles($context['outputDir'], $context['slotName']);

        return new JsonResponse(['data' => ['files' => $files]]);
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/patches/{filename}', methods: ['GET'])]
    public function download(Request $request, string $weeklyRunId, string $entryId, string $filename): Response
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        if (str_contains(strtolower($filename), '_spoiler')) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        $context = $this->patchQuery->forEntry($weeklyRunId, $entryId, $user->getId());
        if (null === $context) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Entrée introuvable.', 404);
        }

        if ('durable' === $context['type']) {
            $artifact = $this->reader->extractEntry($context['outputKey'], $filename);
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

        if (null !== $context['slotName'] && pathinfo($filename, \PATHINFO_FILENAME) !== $context['slotName']) {
            return $this->apiAccessGuard->errorResponse('forbidden', "Ce fichier n'appartient pas à votre entrée.", 403);
        }

        $outputDir = realpath($context['outputDir']);
        if (false === $outputDir) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $filePath = realpath($outputDir.'/'.$filename);
        if (false === $filePath || !str_starts_with($filePath, $outputDir.\DIRECTORY_SEPARATOR)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        if (!is_file($filePath)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    /**
     * A downloadable weekly patch is any output-archive entry that is not the shared multidata
     * (.archipelago) nor a spoiler. A weekly run is a single shared seed, so every entrant of the run
     * is entitled to its patch(es); only the multidata and spoiler are withheld. Mirrors the local
     * branch's findPatchFiles exclusions.
     */
    public static function isDownloadablePatch(string $filename): bool
    {
        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return false;
        }

        return !str_contains(strtolower($filename), '_spoiler');
    }

    /**
     * @return list<string>
     */
    private function findPatchFiles(string $outputDir, ?string $slotName): array
    {
        if (!is_dir($outputDir)) {
            return [];
        }

        $files = [];
        foreach (glob($outputDir.'/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            if ('archipelago' === strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
                continue;
            }

            if (str_contains(strtolower(basename($path)), '_spoiler')) {
                continue;
            }

            if (null !== $slotName && pathinfo($path, \PATHINFO_FILENAME) !== $slotName) {
                continue;
            }

            $files[] = basename($path);
        }

        sort($files);

        return $files;
    }
}
