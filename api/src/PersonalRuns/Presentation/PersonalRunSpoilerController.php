<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunSpoilerDownload;
use App\Sessions\Application\SpoilerArtifact;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lets the run **owner** (or an **admin**) download the generated spoiler log of a private
 * run, served from durable storage (MinIO) so it works whatever the run's state. Regular
 * participants get 403. The multidata and other players' patches are never exposed here.
 */
final readonly class PersonalRunSpoilerController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunSpoilerDownload $spoilerDownload,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/spoiler', name: 'api_runs_spoiler_download', methods: ['GET'])]
    public function download(Request $request, string $runId): Response
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // ROLE_ADMIN here is a display/role gate (not a membership gate), allowed per
        // api/CLAUDE.md AC-M3: an admin may retrieve the spoiler of any private run.
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $result = $this->spoilerDownload->execute($runId, $user->getId(), $isAdmin);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $spoiler = $result['spoiler'];
        if (!$spoiler instanceof SpoilerArtifact) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Spoiler non disponible.', 404);
        }

        $contents = $spoiler->contents;
        $safeFilename = basename($spoiler->filename);

        $streamed = new StreamedResponse(static function () use ($contents): void {
            echo $contents;
        });
        $streamed->headers->set('Content-Type', 'application/octet-stream');
        $streamed->headers->set('Content-Disposition', 'attachment; filename="'.$safeFilename.'"');

        return $streamed;
    }
}
