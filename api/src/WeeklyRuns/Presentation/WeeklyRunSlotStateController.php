<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use App\WeeklyRuns\Application\WeeklyRunSlotQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WeeklyRunSlotStateController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyRunSlotQuery $weeklyRunSlotQuery,
        private HubInterface $mercureHub,
        private HttpClientInterface $httpClient,
        private string $runnerBaseUrl,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/reachable', methods: ['GET'])]
    public function reachable(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        $bridgeHost = $this->bridgeHost();

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/reachable/%d', $bridgeHost, $info['bridgePort'], $slotIndex),
                ['timeout' => 130],
            );
            $data = $response->toArray();

            $data = $this->stripItemRewards($data);

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/hints', methods: ['GET'])]
    public function hints(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        $bridgeHost = $this->bridgeHost();

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/hints/%d', $bridgeHost, $info['bridgePort'], $slotIndex),
                ['timeout' => 5],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/item-locations', methods: ['GET'])]
    public function itemLocations(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        $bridgeHost = $this->bridgeHost();

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/item-locations/%d', $bridgeHost, $info['bridgePort'], $slotIndex),
                ['timeout' => 5],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/reachable-token', methods: ['GET'])]
    public function reachableToken(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        return $this->buildMercureToken(
            'runs/'.$info['externalSessionId'].'/slots/'.$slotIndex.'/reachable',
        );
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/hints-token', methods: ['GET'])]
    public function hintsToken(Request $request, string $runId, string $entryId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        return $this->buildMercureToken(
            'runs/'.$info['externalSessionId'].'/slots/'.$slotIndex.'/hints',
        );
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/players', methods: ['GET'])]
    public function players(Request $request, string $runId, string $entryId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        $bridgeHost = $this->bridgeHost();

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/state', $bridgeHost, $info['bridgePort']),
                ['timeout' => 3],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/weekly-runs/{runId}/entries/{entryId}/players-token', methods: ['GET'])]
    public function playersToken(Request $request, string $runId, string $entryId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $info = $this->weeklyRunSlotQuery->findLaunchedEntryInfo($runId, $entryId, $user->getId(), $this->isAdmin($user));

        if ('ok' !== $info['status']) {
            return $this->errorFromStatus($info['status']);
        }

        return $this->buildMercureToken('runs/'.$info['externalSessionId'].'/players');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function errorFromStatus(string $status): JsonResponse
    {
        return match ($status) {
            'not_found' => $this->apiAccessGuard->errorResponse('not_found', 'Entrée introuvable.', 404),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403),
            default => $this->apiAccessGuard->errorResponse('not_launched', 'La partie n\'a pas encore été lancée.', 409),
        };
    }

    private function buildMercureToken(string $topic): JsonResponse
    {
        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');

        $factory = $this->mercureHub->getFactory();
        if (null === $factory) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Service de token non disponible.', 503);
        }

        $token = $factory->create(
            subscribe: [$topic],
            additionalClaims: ['exp' => $expiresAt],
        );

        return new JsonResponse([
            'data' => [
                'token' => $token,
                'hubUrl' => $this->mercureHub->getPublicUrl(),
                'topic' => $topic,
            ],
        ]);
    }

    private function bridgeHost(): string
    {
        $host = parse_url($this->runnerBaseUrl, PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function stripItemRewards(array $data): array
    {
        $strip = static function (array $locations): array {
            return array_map(static function (mixed $entry): mixed {
                if (is_array($entry)) {
                    unset($entry['item']);
                }

                return $entry;
            }, $locations);
        };

        foreach (['reachable_unchecked', 'reachable_checked', 'unreachable_unchecked', 'checked_unreachable'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $strip($data[$key]);
            }
        }

        if (isset($data['spheres']) && is_array($data['spheres'])) {
            $data['spheres'] = array_map(static function (mixed $sphere) use ($strip): mixed {
                if (!is_array($sphere)) {
                    return $sphere;
                }
                if (isset($sphere['locations']) && is_array($sphere['locations'])) {
                    $sphere['locations'] = $strip($sphere['locations']);
                }

                return $sphere;
            }, $data['spheres']);
        }

        return $data;
    }
}
