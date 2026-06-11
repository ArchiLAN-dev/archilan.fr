<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunPatchQuery;
use App\Sessions\Application\SessionOutputArtifactReaderInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lets a private-run participant download the patch(es) generated for their own slot, read
 * from the run's durable output archive on MinIO (so it works whatever the run's state).
 * The shared multidata (.archipelago), spoilers, and other players' patches are never exposed.
 */
final readonly class PersonalRunPatchController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunPatchQuery $patchQuery,
        private SessionOutputArtifactReaderInterface $reader,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/patches', name: 'api_runs_patches_list', methods: ['GET'])]
    public function list(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $context = $this->patchQuery->forParticipant($runId, $user->getId());
        if (null === $context) {
            return new JsonResponse(['data' => ['files' => []]]);
        }

        $files = array_values(array_filter(
            $this->reader->listEntries($context['outputKey']),
            fn (string $filename): bool => self::belongsToOwnSlot($filename, $context['slotNames']),
        ));
        sort($files);

        return new JsonResponse(['data' => ['files' => $files]]);
    }

    #[Route('/api/v1/runs/{runId}/patches/{filename}', name: 'api_runs_patches_download', methods: ['GET'])]
    public function download(Request $request, string $runId, string $filename): Response
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $context = $this->patchQuery->forParticipant($runId, $user->getId());
        if (null === $context) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        if (!self::belongsToOwnSlot($filename, $context['slotNames'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', "Ce fichier n'appartient pas à votre slot.", 403);
        }

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

    /**
     * Whether an output filename is a patch belonging to one of the given slot names.
     *
     * AP patch files are named "AP_{seed}_P{slotNumber}_{slotName}.{ext}" (the slot
     * name is a suffix, and may itself contain underscores). The slot name is matched
     * exactly - extracted after the "_P{n}_" delimiter - so one player can't grab a
     * file whose slot name merely ends with theirs. The shared multidata
     * (.archipelago) and any *_spoiler* file are never patches.
     *
     * @param list<string> $slotNames
     */
    public static function belongsToOwnSlot(string $filename, array $slotNames): bool
    {
        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return false;
        }
        if (str_contains(strtolower($filename), '_spoiler')) {
            return false;
        }

        $stem = pathinfo($filename, \PATHINFO_FILENAME);
        $slot = 1 === preg_match('/_P\d+_(.+)$/', $stem, $matches) ? $matches[1] : $stem;

        return in_array($slot, $slotNames, true);
    }
}
