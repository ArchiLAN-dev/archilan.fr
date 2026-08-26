<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Identity\Domain\Entity\User;
use App\Sessions\Application\Query\PlayersSnapshotQuery;
use App\Sessions\Application\Query\SessionQuery;
use App\Sessions\Application\Query\SessionSlotOwnersQuery;
use App\Sessions\Domain\Entity\Session;
use App\Shared\Application\Support\BridgeEndpoint;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Archilan\BridgeClient\Enum\HintStatus;
use Archilan\BridgeClient\Slots\Response\Hint;
use Archilan\BridgeClient\Slots\Response\ItemLocation;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PlayerStateController
{
    use RequiresAuthTrait;

    /** Bridge detail surfaced to the caller, capped so a stack-like payload never lands in the UI. */
    private const int DETAIL_MAX_LENGTH = 300;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private PlayersSnapshotQuery $playersSnapshotQuery,
        private SessionSlotOwnersQuery $slotOwnersQuery,
        private HubInterface $mercureHub,
        private HttpClientInterface $httpClient,
        private BridgeClientPool $bridgeClientPool,
        private LoggerInterface $logger,
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

        // Not running (idle/stopped/finished - the everyday case since epic 17's auto-idle), no
        // port, or a dead bridge: serve the last pushed snapshot instead of erroring, so the
        // Progression tab keeps showing the last known state (story 17.21). The historical error
        // semantics only apply when no snapshot was ever recorded.
        if (Session::STATUS_RUNNING !== $session['status']) {
            $stale = $this->staleSnapshotResponse($runId);
            if (null !== $stale) {
                return $stale;
            }

            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                sprintf('La session est en état "%s", pas encore en cours.', $session['status']),
                409,
            );
        }

        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->staleSnapshotResponse($runId)
                ?? $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                BridgeEndpoint::url($runId, '/state'),
                ['timeout' => 3],
            );
            $data = $response->toArray();

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            return $this->staleSnapshotResponse($runId) ?? $this->bridgeFailure($e, $runId, null, 'players');
        }
    }

    /** The last pushed players state, marked stale - or null when none was ever recorded. */
    private function staleSnapshotResponse(string $sessionId): ?JsonResponse
    {
        $snapshot = $this->playersSnapshotQuery->execute($sessionId);
        if (null === $snapshot) {
            return null;
        }

        return new JsonResponse([
            'data' => $snapshot['payload'],
            'meta' => ['stale' => true, 'updatedAt' => $snapshot['updatedAt']],
        ]);
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

    /**
     * Who owns each slot of the session, by Archipelago slot name.
     *
     * Session-scoped rather than slot-scoped on purpose: a page shows the goal celebration of every
     * slot it watches, not just the caller's. It carries no spoiler either - just the pseudo already
     * public on the member's profile, for a session the caller is authorized on.
     */
    #[Route('/api/v1/sessions/{sessionId}/slot-owners', methods: ['GET'])]
    public function slotOwners(Request $request, string $sessionId): JsonResponse
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

        return new JsonResponse(['data' => ['slots' => $this->slotOwnersQuery->execute($session['id'])]]);
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

        // Story 16.18: a seed generated elsewhere carries no yamls, so the world cannot be rebuilt
        // and the detailed progression does not exist. Say so instead of letting the request fall
        // into a generation error, and spend no container on it.
        if ($session['importedSeed']) {
            return $this->apiAccessGuard->errorResponse(
                'detailed_progression_unavailable',
                'La progression détaillée n\'est pas disponible sur une partie importée.',
                409,
            );
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

        // Hints reveal item + location (spoilers), so only the slot owner (or admin) may read them.
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
            $response = $bridge->slots()->hints($slotIndex);

            $hints = array_map(static fn (Hint $h): array => [
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
            ], $response->hints);

            return new JsonResponse(['data' => [
                'slot' => $response->slot,
                'hints' => $hints,
                'hintsUsed' => $response->hintsUsed,
                'hintPointsAvailable' => $response->hintPointsAvailable,
                'hintCost' => $response->hintCost,
            ]]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'hints');
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

        // The hints topic carries spoilers (unlike the public reachable/feed overlay topics), so a
        // subscribe token is issued only for the caller's own slot (or admin).
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
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

        // Story 9.31: the slot owner (or admin) may buy a paid hint with their own points - and only
        // their own, so a player cannot spend another slot's hint points (issue #253).
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

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
            $bridge = $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
            $response = $bridge->slots()->requestHint($slotIndex, $locationId, $free);

            return new JsonResponse(['data' => [
                'ok' => true,
                'slot' => $response->slot,
                'locationId' => $response->locationId,
                'free' => $response->free,
            ]]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'hints/request');
        }
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/{locationId}', methods: ['PATCH'], requirements: ['locationId' => '\d+'])]
    public function updateHintStatus(Request $request, string $sessionId, int $slotIndex, int $locationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($sessionId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        // The slot owner (or admin) sets the hint priority for their OWN slot only: a player must not
        // be able to change another player's hint priority (issue #253).
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $bridgePort = $session['bridgePort'];
        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Corps de requête invalide.', 422);
        }

        $statusRaw = $body['status'] ?? null;
        $status = is_int($statusRaw) ? HintStatus::tryFrom($statusRaw) : null;
        // Players control priority/avoid/no_priority/unspecified; "found" (40) is bridge-managed.
        $settable = [HintStatus::Unspecified, HintStatus::NoPriority, HintStatus::Avoid, HintStatus::Priority];
        if (null === $status || !in_array($status, $settable, true)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'status invalide (0, 10, 20 ou 30 attendu).', 422);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
            $response = $bridge->slots()->updateHint($slotIndex, $locationId, $status);

            return new JsonResponse(['data' => [
                'ok' => true,
                'slot' => $response->slot,
                'locationId' => $response->locationId,
                'status' => $status->value,
                'statusName' => $status->label(),
            ]]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'hints/update');
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

        // Story 9.31: the slot owner (or admin) may buy a paid hint with their own points - and only
        // their own, so a player cannot spend another slot's hint points (issue #253).
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

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
            $bridge = $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
            $response = $bridge->slots()->requestHintItem($slotIndex, $itemName, $free);

            return new JsonResponse(['data' => [
                'ok' => true,
                'slot' => $response->slot,
                'itemName' => $response->itemName,
                'free' => $response->free,
            ]]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'hints/request-item');
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

        // Item locations expose where a slot's items are (spoilers), so only the slot owner (or admin)
        // may read them - session-level authorization is not enough (issue #252).
        if (!$this->ownsSlot($user, $session['id'], $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $bridge = $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId));
            $response = $bridge->slots()->itemLocations($slotIndex);

            $locations = array_map(static fn (ItemLocation $loc): array => [
                'itemId' => $loc->itemId,
                'itemName' => $loc->itemName,
                'locationId' => $loc->locationId,
                'locationName' => $loc->locationName,
                'findingPlayer' => $loc->findingSlot,
                'findingPlayerName' => $loc->findingPlayerName,
                'checkStatus' => $loc->checkStatus,
            ], $response->locations);

            return new JsonResponse(['data' => [
                'slot' => $response->slot,
                'locations' => $locations,
            ]]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'item-locations');
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

        // Story 16.18: a seed generated elsewhere carries no yamls, so the world cannot be rebuilt
        // and the detailed progression does not exist. Say so instead of letting the request fall
        // into a generation error, and spend no container on it.
        if ($session['importedSeed']) {
            return $this->apiAccessGuard->errorResponse(
                'detailed_progression_unavailable',
                'La progression détaillée n\'est pas disponible sur une partie importée.',
                409,
            );
        }

        if (Session::STATUS_RUNNING !== $session['status']) {
            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                'La session n\'est pas en cours.',
                409,
            );
        }

        $bridgePort = $session['bridgePort'];

        if (null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                BridgeEndpoint::url($sessionId, sprintf('/reachable/%d', $slotIndex)),
                ['timeout' => 130],
            );
            $data = $response->toArray();

            $optionalUser = $this->apiAccessGuard->optionalUser($request);
            $isAdmin = $optionalUser instanceof User && in_array('ROLE_ADMIN', $optionalUser->getRoles(), true);

            if (!$isAdmin) {
                $data = $this->stripItemRewards($data);
            }

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $e) {
            return $this->bridgeFailure($e, $sessionId, $slotIndex, 'reachable');
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function stripItemRewards(array $data): array
    {
        $strip = (static fn (array $locations): array => array_map(static function (mixed $entry): mixed {
            if (is_array($entry)) {
                unset($entry['item']);
            }

            return $entry;
        }, $locations));

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

    /**
     * Slot-scoped authorization (issues #252 / #253): being authorized for the session is not enough,
     * the caller must own the slot at $slotIndex. Admins bypass. Prevents a registrant/participant from
     * reading another player's item locations / hints or acting on their slot.
     */
    private function ownsSlot(User $user, string $sessionId, int $slotIndex): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->sessionQuery->doesUserOwnSlot($user->getId(), $sessionId, $slotIndex);
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * Turns a failed bridge call into a response that says what actually went wrong (issue #278).
     *
     * These calls used to be wrapped in a bare `catch (\Throwable)` that logged nothing and replaced
     * every failure with "Bridge non disponible." - so a bridge that was up and answering
     * `500 {"detail": "reachability generation failed for <game>: ..."}` looked exactly like a bridge
     * that did not exist, and the only real diagnostic we had was discarded. The bridge SDK already
     * puts the payload's `detail` in the exception message (HttpTransport::mapError).
     */
    private function bridgeFailure(\Throwable $e, string $sessionId, ?int $slotIndex, string $endpoint): JsonResponse
    {
        $this->logger->warning('sessions.bridge_call_failed', [
            'sessionId' => $sessionId,
            'slotIndex' => $slotIndex,
            'endpoint' => $endpoint,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        // Connection-level failure (refused, DNS, network timeout): the bridge really is unreachable.
        if ($e instanceof TransportExceptionInterface || $e->getPrevious() instanceof TransportExceptionInterface) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        // The bridge answered - surface its own message rather than masking it as "unavailable".
        $detail = $this->bridgeDetail($e);

        if (str_contains(strtolower($detail), 'timed out')) {
            return $this->apiAccessGuard->errorResponse('bridge_timeout', $detail, 504);
        }

        return $this->apiAccessGuard->errorResponse('bridge_error', $detail, 502);
    }

    /**
     * The bridge's own error text: the JSON `detail` when the raw HTTP client threw on a non-2xx,
     * otherwise the exception message (which the SDK already built from that same `detail`).
     */
    private function bridgeDetail(\Throwable $e): string
    {
        $detail = '';

        if ($e instanceof HttpExceptionInterface) {
            try {
                $decoded = json_decode($e->getResponse()->getContent(false), true);
                if (is_array($decoded) && is_string($decoded['detail'] ?? null)) {
                    $detail = $decoded['detail'];
                }
            } catch (\Throwable) {
                // Body unreadable - fall back to the exception message below.
            }
        }

        if ('' === $detail) {
            $detail = trim($e->getMessage());
        }

        if ('' === $detail) {
            return 'Erreur du bridge.';
        }

        return mb_strlen($detail) > self::DETAIL_MAX_LENGTH
            ? mb_substr($detail, 0, self::DETAIL_MAX_LENGTH).'…'
            : $detail;
    }
}
