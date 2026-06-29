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
            fn (string $filename): bool => self::belongsToOwnSlot($filename, $context['slotNames'], $context['allSlotNames']),
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

        if (!self::belongsToOwnSlot($filename, $context['slotNames'], $context['allSlotNames'])) {
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
     * Whether an output filename is a patch belonging to one of the caller's slot names.
     *
     * AP patch files are named "AP_{seed}_P{slotNumber}_{slotName}.{ext}". Some apworlds append a
     * game/version suffix *after* the slot name (e.g. "masterkafey_SHA" → file
     * "AP_..._P2_masterkafey_SHA_SHAR_0.6.7.apshar"), so the slot name is matched as a prefix at an
     * underscore boundary, not only by exact equality - otherwise those patches are wrongly filtered
     * out.
     *
     * Custom slot names (story 9.37) break the old "one underscore per name" invariant: a name like
     * "master" can be a `_`-boundary prefix of another player's "master_kafey". So the file is
     * attributed to the **single longest** matching name among ALL session slots (`$allSlotNames`),
     * and access is granted only when that winner is one of the caller's (`$ownSlotNames`) - a player
     * can never grab another player's patch even if their name is a prefix of it. The shared multidata
     * (.archipelago) and any *_spoiler* file are never patches.
     *
     * @param list<string>      $ownSlotNames the caller's slots
     * @param list<string>|null $allSlotNames every slot in the session; defaults to $ownSlotNames
     */
    public static function belongsToOwnSlot(string $filename, array $ownSlotNames, ?array $allSlotNames = null): bool
    {
        $allSlotNames ??= $ownSlotNames;

        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return false;
        }
        if (str_contains(strtolower($filename), '_spoiler')) {
            return false;
        }

        $stem = pathinfo($filename, \PATHINFO_FILENAME);
        $captured = 1 === preg_match('/_P\d+_(.+)$/', $stem, $matches) ? $matches[1] : $stem;

        $winner = null;
        foreach ($allSlotNames as $slotName) {
            if ($captured === $slotName || str_starts_with($captured, $slotName.'_')) {
                if (null === $winner || mb_strlen($slotName) > mb_strlen($winner)) {
                    $winner = $slotName;
                }
            }
        }

        return null !== $winner && in_array($winner, $ownSlotNames, true);
    }
}
