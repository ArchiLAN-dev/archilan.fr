<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use App\WeeklyRuns\Application\WeeklyEntryPatchQuery;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyEntryPatchController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyEntryPatchQuery $patchQuery,
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

        $context = $this->patchQuery->forEntry($weeklyRunId, $entryId, $user->getId());
        if (null === $context) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Entrée introuvable.', 404);
        }

        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        if (str_contains(strtolower($filename), '_spoiler')) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        // Post-launch: verify the file belongs to this player's slot.
        if (null !== $context['slotName']) {
            $stem = pathinfo($filename, \PATHINFO_FILENAME);
            if ($stem !== $context['slotName']) {
                return $this->apiAccessGuard->errorResponse('forbidden', "Ce fichier n'appartient pas à votre entrée.", 403);
            }
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
