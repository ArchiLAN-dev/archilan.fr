<?php

declare(strict_types=1);

namespace App\Trackers\Presentation\Controller\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Trackers\Application\Command\CheckTrackerPackUpdates;
use App\Trackers\Application\Command\ImportTrackerPack;
use App\Trackers\Application\Command\RecordTrackerPackCompatibility;
use App\Trackers\Application\Command\RollbackTrackerPack;
use App\Trackers\Application\Command\TrackerPackChanges;
use App\Trackers\Application\Command\TrackerPackCompatibilityReport;
use App\Trackers\Application\Command\UpdateTrackerPack;
use App\Trackers\Application\Command\UpgradeTrackerPack;
use App\Trackers\Application\Query\AdminTrackerPackQuery;
use App\Trackers\Domain\Entity\TrackerPackCompatibility;
use App\Trackers\Domain\Enum\TrackerPackCompatibilityStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stories 54.1 to 54.3: the PopTracker packs of a game, imported from a GitHub link, offered to players or not, then
 * kept up to date. Admins only. Refusals from the Application layer come as typed failures, mapped to HTTP by the epic-35 listener.
 */
final readonly class AdminTrackerPackController
{
    use RequiresAuthTrait;

    public function __construct(
        private ImportTrackerPack $import,
        private UpdateTrackerPack $update,
        private CheckTrackerPackUpdates $checkUpdates,
        private UpgradeTrackerPack $upgrade,
        private RollbackTrackerPack $rollback,
        private RecordTrackerPackCompatibility $compatibility,
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

    /** Story 54.3: looks for a newer version of this pack now, instead of waiting for the night. */
    #[Route('/api/v1/admin/tracker-packs/{packId}/check-update', name: 'api_admin_tracker_pack_check_update', methods: ['POST'])]
    public function checkUpdate(Request $request, string $packId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $this->checkUpdates->checkOne($packId);

        return new JsonResponse(null, 204);
    }

    /**
     * Story 54.3: installs the update the check found, or, with `{url}`, the version at a GitHub link (same forms as
     * the import), for repositories without proper releases.
     */
    #[Route('/api/v1/admin/tracker-packs/{packId}/update', name: 'api_admin_tracker_pack_upgrade', methods: ['POST'])]
    public function upgrade(Request $request, string $packId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $url = $this->payload($request)['url'] ?? null;
        if (null !== $url && !is_string($url)) {
            return $this->invalidJson();
        }

        return new JsonResponse(['data' => $this->upgrade->upgrade($packId, $url, $admin->getId()), 'meta' => []]);
    }

    /** Story 54.3: serves again one of the versions the pack kept. */
    #[Route('/api/v1/admin/tracker-packs/{packId}/rollback', name: 'api_admin_tracker_pack_rollback', methods: ['POST'])]
    public function rollback(Request $request, string $packId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $versionId = $this->payload($request)['versionId'] ?? null;
        if (!is_string($versionId)) {
            return $this->invalidJson();
        }

        return new JsonResponse(['data' => $this->rollback->rollback($packId, $versionId, $admin->getId()), 'meta' => []]);
    }

    /**
     * Story 54.8: what the admin's try of one variant found. `{variantUid, status: ok|warnings|failed, errors: [{kind,
     * message, file?, line?}], unsupportedApis: [string], engineVersion}`. Texts are cut to their bound, lists over
     * theirs are refused.
     */
    #[Route('/api/v1/admin/tracker-pack-versions/{versionId}/compatibility', name: 'api_admin_tracker_pack_compatibility', methods: ['POST'])]
    public function compatibility(Request $request, string $versionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $report = self::report($this->payload($request));
        if (null === $report) {
            return $this->invalidJson();
        }
        $this->compatibility->record($versionId, $report, $admin->getId());

        return new JsonResponse(null, 204);
    }

    /** @param array<mixed> $payload */
    private static function report(array $payload): ?TrackerPackCompatibilityReport
    {
        $variantUid = $payload['variantUid'] ?? null;
        $status = is_string($payload['status'] ?? null) ? TrackerPackCompatibilityStatus::tryFrom($payload['status']) : null;
        $engineVersion = $payload['engineVersion'] ?? null;
        $rawErrors = $payload['errors'] ?? null;
        $rawApis = $payload['unsupportedApis'] ?? null;
        if (!is_string($variantUid) || '' === $variantUid || mb_strlen($variantUid) > TrackerPackCompatibility::MAX_NAME
            || null === $status || !is_int($engineVersion) || $engineVersion < 1
            || !is_array($rawErrors) || !array_is_list($rawErrors) || !is_array($rawApis) || !array_is_list($rawApis)) {
            return null;
        }
        $errors = [];
        foreach ($rawErrors as $error) {
            if (!is_array($error) || !is_string($error['kind'] ?? null) || !is_string($error['message'] ?? null)) {
                return null;
            }
            $file = $error['file'] ?? null;
            $line = $error['line'] ?? null;
            if ((null !== $file && !is_string($file)) || (null !== $line && !is_int($line))) {
                return null;
            }
            $errors[] = [
                'kind' => mb_substr($error['kind'], 0, 40),
                'message' => mb_substr($error['message'], 0, TrackerPackCompatibility::MAX_MESSAGE),
                'file' => null === $file ? null : mb_substr($file, 0, 255),
                'line' => $line,
            ];
        }
        $apis = [];
        foreach ($rawApis as $api) {
            if (!is_string($api)) {
                return null;
            }
            $apis[] = mb_substr($api, 0, TrackerPackCompatibility::MAX_NAME);
        }

        return new TrackerPackCompatibilityReport($variantUid, $status, $errors, array_values(array_unique($apis)), $engineVersion);
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
