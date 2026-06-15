<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Identity\Domain\User;
use App\Sessions\Application\SessionQuery;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Archilan\BridgeClient\Slots\Response\Hint;
use Archilan\BridgeClient\Slots\Response\ItemLocation;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PlayerStateController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private HubInterface $mercureHub,
        private HttpClientInterface $httpClient,
        private BridgeClientPool $bridgeClientPool,
        private string $bridgeHttpHost,
    ) {
    }

    // BRIDGE CLIENT GAP: /state endpoint not available in bridge-client; kept on raw HTTP.
    #[Route('/api/v1/sessions/{runId}/players', methods: ['GET'])]
    public function players(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                sprintf('La session est en état "%s", pas encore en cours.', $session['status']),
                409,
            );
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/state', $host, $bridgePort),
                ['timeout' => 3],
            );
            $data = $response->toArray();

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{runId}/players-token', methods: ['GET'])]
    public function playersToken(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/players';

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

    #[Route('/api/v1/sessions/{runId}/slots/{slotIndex}/reachable-token', methods: ['GET'])]
    public function reachableToken(Request $request, string $runId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/slots/'.$slotIndex.'/reachable';

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

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints', methods: ['GET'])]
    public function slotHints(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, sprintf('http://%s:%d', $host, $bridgePort));
            $response = $bridge->slots()->hints($slotIndex);

            $hints = array_map(static function (Hint $h): array {
                return [
                    'receivingPlayer' => $h->receivingSlot,
                    'receivingPlayerName' => $h->receivingPlayerName,
                    'findingPlayer' => $h->findingSlot,
                    'findingPlayerName' => $h->findingPlayerName,
                    'locationId' => $h->locationId,
                    'locationName' => $h->locationName,
                    'itemId' => $h->itemId,
                    'itemName' => $h->itemName,
                    'itemFlags' => $h->itemFlags,
                    'entrance' => $h->entrance,
                    'status' => $h->status->value,
                    'statusName' => $h->status->label(),
                    'found' => $h->found,
                ];
            }, $response->hints);

            return new JsonResponse(['data' => [
                'slot' => $response->slot,
                'hints' => $hints,
                'hintsUsed' => $response->hintsUsed,
                'hintPointsAvailable' => $response->hintPointsAvailable,
                'hintCost' => $response->hintCost,
            ]]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{runId}/slots/{slotIndex}/hints-token', methods: ['GET'])]
    public function hintsToken(Request $request, string $runId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/slots/'.$slotIndex.'/hints';

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

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/request', methods: ['POST'])]
    public function requestHint(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        // Story 9.31: the slot owner (or admin) may buy a paid hint with their own points.
        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Corps de requête invalide.', 422);
        }
        $locationId = $body['location_id'] ?? null;
        // Only admins may use the free/admin path; a player can only ever pay (story 9.31).
        $free = $this->isAdmin($user) && (bool) ($body['free'] ?? false);

        if (!is_int($locationId) || $locationId <= 0) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'location_id (entier > 0) requis.', 422);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, sprintf('http://%s:%d', $host, $bridgePort));
            $response = $bridge->slots()->requestHint($slotIndex, $locationId, $free);

            return new JsonResponse(['data' => [
                'ok' => true,
                'slot' => $response->slot,
                'locationId' => $response->locationId,
                'free' => $response->free,
            ]]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/request-item', methods: ['POST'])]
    public function requestHintItem(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        // Story 9.31: the slot owner (or admin) may buy a paid hint with their own points.
        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Corps de requête invalide.', 422);
        }
        $itemName = $body['itemName'] ?? null;
        // Only admins may use the free/admin path; a player can only ever pay (story 9.31).
        $free = $this->isAdmin($user) && (bool) ($body['free'] ?? false);

        if (!is_string($itemName) || '' === trim($itemName)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'itemName (non vide) requis.', 422);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, sprintf('http://%s:%d', $host, $bridgePort));
            $response = $bridge->slots()->requestHintItem($slotIndex, $itemName, $free);

            return new JsonResponse(['data' => [
                'ok' => true,
                'slot' => $response->slot,
                'itemName' => $response->itemName,
                'free' => $response->free,
            ]]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/item-locations', methods: ['GET'])]
    public function slotItemLocations(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session['id'], $session['eventId'])) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, sprintf('http://%s:%d', $host, $bridgePort));
            $response = $bridge->slots()->itemLocations($slotIndex);

            $locations = array_map(static function (ItemLocation $loc): array {
                return [
                    'itemId' => $loc->itemId,
                    'itemName' => $loc->itemName,
                    'locationId' => $loc->locationId,
                    'locationName' => $loc->locationName,
                    'findingPlayer' => $loc->findingSlot,
                    'findingPlayerName' => $loc->findingPlayerName,
                    'checkStatus' => $loc->checkStatus,
                ];
            }, $response->locations);

            return new JsonResponse(['data' => [
                'slot' => $response->slot,
                'locations' => $locations,
            ]]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    // BRIDGE CLIENT GAP: ReachableResponse reads camelCase keys but bridge returns snake_case;
    // kept on raw HTTP until the package is updated to handle snake_case normalization.
    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/reachable', methods: ['GET'])]
    public function slotReachable(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                'La session n\'est pas en cours.',
                409,
            );
        }

        $host = $this->bridgeHttpHost;
        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/reachable/%d', $host, $bridgePort, $slotIndex),
                ['timeout' => 130],
            );
            $data = $response->toArray();

            $optionalUser = $this->apiAccessGuard->optionalUser($request);
            $isAdmin = $optionalUser instanceof User && in_array('ROLE_ADMIN', $optionalUser->getRoles(), true);

            if (!$isAdmin) {
                $data = $this->stripItemRewards($data);
            }

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
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

    private function isAuthorized(User $user, string $sessionId, string $eventId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->sessionQuery->isUserAuthorizedForSession($user->getId(), $eventId, $sessionId);
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
