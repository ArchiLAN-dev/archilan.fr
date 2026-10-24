<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Query\PersonalRunPatchQuery;
use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Sessions\Application\Support\SlotPatchFile;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\PatchDownloadUrl;
use App\Shared\Presentation\Support\RequiresAuthTrait;
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
        private PatchDownloadUrl $signedUrl,
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

        // Chaque fichier part avec son lien public signé (story 16.16) : le joueur copie l'adresse
        // et l'envoie à qui doit jouer ce slot, sans compte ArchiLAN. Seuls les fichiers déjà
        // filtrés sur ses propres slots en reçoivent un.
        return new JsonResponse(['data' => ['files' => array_map(
            fn (string $filename): array => [
                'name' => $filename,
                'url' => $this->signedUrl->forArchive($context['outputKey'], $filename),
            ],
            $files,
        )]]);
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
     * Whether an output filename is a patch belonging to one of the caller's slot names (the rule now
     * lives in SlotPatchFile, shared with the event downloads since story 17.32).
     *
     * @param list<string>      $ownSlotNames the caller's slots
     * @param list<string>|null $allSlotNames every slot in the session; defaults to $ownSlotNames
     */
    public static function belongsToOwnSlot(string $filename, array $ownSlotNames, ?array $allSlotNames = null): bool
    {
        return SlotPatchFile::belongsTo($filename, $ownSlotNames, $allSlotNames);
    }
}
