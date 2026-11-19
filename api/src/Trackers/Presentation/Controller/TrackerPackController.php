<?php

declare(strict_types=1);

namespace App\Trackers\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Trackers\Application\Query\PublicTrackerPackQuery;
use App\Trackers\Application\Query\TrackerPackArchiveQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 54.2: the packs players are offered for a game, and their zips. No account needed: the packs are public on
 * GitHub, and only the packs an admin enabled are listed or served.
 */
final readonly class TrackerPackController
{
    public function __construct(
        private PublicTrackerPackQuery $packs,
        private TrackerPackArchiveQuery $archives,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/games/{gameId}/tracker-packs', name: 'api_game_tracker_packs', methods: ['GET'])]
    public function list(string $gameId): JsonResponse
    {
        return new JsonResponse(['data' => $this->packs->enabledForGame($gameId), 'meta' => []]);
    }

    /** The zip as stored, byte for byte. An admin also reaches the versions of packs not yet offered. */
    #[Route('/api/v1/tracker-pack-versions/{versionId}/archive', name: 'api_tracker_pack_archive', methods: ['GET'])]
    public function archive(Request $request, string $versionId): Response
    {
        $user = $this->apiAccessGuard->optionalUser($request);
        $asAdmin = null !== $user && in_array('ROLE_ADMIN', $user->getRoles(), true);

        $fingerprint = $this->archives->fingerprint($versionId, $asAdmin);
        if (null === $fingerprint) {
            return $this->apiAccessGuard->errorResponse('tracker_pack_not_found', 'Pack introuvable.', 404);
        }
        // Story 54.13: a browser that holds this zip gets a 304, and the object store is not read.
        $notModified = new Response(null, 200, ['Cache-Control' => 'private, max-age=86400, immutable']);
        $notModified->setEtag($fingerprint);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $archive = $this->archives->forDownload($versionId, $asAdmin);
        if (null === $archive) {
            return $this->apiAccessGuard->errorResponse('tracker_pack_not_found', 'Pack introuvable.', 404);
        }

        $response = new Response($archive->contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $archive->filename),
            // A version never changes; private, so a pack disabled later does not linger in shared caches.
            'Cache-Control' => 'private, max-age=86400, immutable',
        ]);
        $response->setEtag($archive->sha256);

        return $response;
    }
}
