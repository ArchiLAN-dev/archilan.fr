<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Sessions\Application\Query\RegistrationPatchQuery;
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
 * Lets an event registrant download the patch(es) generated for their own slots, read from the
 * session's durable output archive like a private run (story 17.32): available once generated,
 * whatever the session's state. The multidata, the spoiler and other players' patches never leave.
 */
final readonly class PlayerPatchController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private RegistrationPatchQuery $patchQuery,
        private SessionOutputArtifactReaderInterface $reader,
        private PatchDownloadUrl $signedUrl,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/patches', methods: ['GET'])]
    public function list(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $context = $this->patchQuery->forRegistration($registrationId, $user->getId());
        if (null === $context) {
            return new JsonResponse(['data' => ['files' => []]]);
        }

        $files = array_values(array_filter(
            $this->reader->listEntries($context['outputKey']),
            fn (string $filename): bool => SlotPatchFile::belongsTo($filename, $context['slotNames'], $context['allSlotNames']),
        ));
        sort($files);

        // Lien public signé par fichier (story 16.16), pour que le joueur puisse l'envoyer.
        return new JsonResponse(['data' => ['files' => array_map(
            fn (string $filename): array => [
                'name' => $filename,
                'url' => $this->signedUrl->forArchive($context['outputKey'], $filename),
            ],
            $files,
        )]]);
    }

    #[Route('/api/v1/registrations/{registrationId}/patches/{filename}', methods: ['GET'])]
    public function download(Request $request, string $registrationId, string $filename): Response
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $context = $this->patchQuery->forRegistration($registrationId, $user->getId());
        if (null === $context) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        if (!SlotPatchFile::belongsTo($filename, $context['slotNames'], $context['allSlotNames'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', "Ce fichier n'appartient pas à votre inscription.", 403);
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
}
