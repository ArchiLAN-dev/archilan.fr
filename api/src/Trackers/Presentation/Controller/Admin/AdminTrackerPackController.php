<?php

declare(strict_types=1);

namespace App\Trackers\Presentation\Controller\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Trackers\Application\Command\ImportTrackerPack;
use App\Trackers\Application\Command\TrackerPackChanges;
use App\Trackers\Application\Command\UpdateTrackerPack;
use App\Trackers\Application\Query\AdminTrackerPackQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stories 54.1 and 54.2: the PopTracker packs of a game, imported from a GitHub link, then offered to players or not.
 * Admins only. Refusals from the Application layer come as typed failures, mapped to HTTP by the epic-35 listener.
 */
final readonly class AdminTrackerPackController
{
    use RequiresAuthTrait;

    public function __construct(
        private ImportTrackerPack $import,
        private UpdateTrackerPack $update,
        private AdminTrackerPackQuery $query,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/games/{gameId}/tracker-packs', name: 'api_admin_game_tracker_packs', methods: ['GET'])]
    public function list(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forGame($gameId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/games/{gameId}/tracker-packs', name: 'api_admin_game_tracker_packs_import', methods: ['POST'])]
    public function import(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $url = $this->payload($request)['url'] ?? null;
        if (!is_string($url)) {
            return $this->invalidJson();
        }

        return new JsonResponse(['data' => $this->import->import($gameId, $url, $admin->getId()), 'meta' => []], 201);
    }

    /**
     * Story 54.2: any of `enabled`, `isDefault` (true only), `defaultVariantUid` (null = the first variant) and
     * `licenseAcknowledged`. Absent fields are left as they are.
     */
    #[Route('/api/v1/admin/tracker-packs/{packId}', name: 'api_admin_tracker_pack_update', methods: ['PATCH'])]
    public function update(Request $request, string $packId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $enabled = $payload['enabled'] ?? null;
        $isDefault = $payload['isDefault'] ?? null;
        $licenseAcknowledged = $payload['licenseAcknowledged'] ?? null;
        $setsVariant = array_key_exists('defaultVariantUid', $payload);
        $variant = $payload['defaultVariantUid'] ?? null;
        if ((null !== $enabled && !is_bool($enabled))
            || (null !== $isDefault && true !== $isDefault)
            || (null !== $licenseAcknowledged && !is_bool($licenseAcknowledged))
            || (null !== $variant && !is_string($variant))) {
            return $this->invalidJson();
        }

        $this->update->update($packId, new TrackerPackChanges($enabled, $isDefault, $setsVariant, $variant, $licenseAcknowledged), $admin->getId());

        return new JsonResponse(null, 204);
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function invalidJson(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
    }
}
