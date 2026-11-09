<?php

declare(strict_types=1);

namespace App\Registrations\Presentation\Controller;

use App\Registrations\Application\Command\ChoosePresenceWindows;
use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Registrations\Application\Query\PresenceWindowsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 52.2: when a member is at the event, set by the member on their seat or by an admin, and the admin timeline. */
final readonly class PresenceWindowsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PresenceWindowsQuery $query,
        private PresenceTimelineQueryInterface $timeline,
        private ChoosePresenceWindows $choose,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/presence', name: 'api_registrations_presence_get', methods: ['GET'])]
    public function show(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $presence = $this->query->forOwner($registrationId, $user->getId());
        if (null === $presence) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $presence, 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/presence', name: 'api_registrations_presence_put', methods: ['PUT'])]
    public function choose(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $windows = $this->windowsFrom($request);
        if (null === $windows) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Plages invalides : dates attendues au format ISO 8601.', 400);
        }

        // Failures (not the caller's, seat cancelled, event over, broken rule) are typed ApplicationFailures (epic 35).
        $this->choose->asMember($registrationId, $user->getId(), $windows);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/presence', name: 'api_admin_registrations_presence_get', methods: ['GET'])]
    public function showForAdmin(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $presence = $this->query->forAdmin($eventId, $registrationId);
        if (null === $presence) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $presence, 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/presence', name: 'api_admin_registrations_presence_put', methods: ['PUT'])]
    public function chooseForAdmin(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $windows = $this->windowsFrom($request);
        if (null === $windows) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Plages invalides : dates attendues au format ISO 8601.', 400);
        }

        $this->choose->asAdmin($eventId, $registrationId, $windows);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/events/{eventId}/presence', name: 'api_admin_events_presence_timeline', methods: ['GET'])]
    public function timeline(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $timeline = $this->timeline->timeline($eventId);
        if (null === $timeline) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $timeline, 'meta' => []]);
    }

    /** @return list<array{\DateTimeImmutable, \DateTimeImmutable}>|null null when the body is not `{windows: [{startsAt, endsAt}]}` with ISO dates */
    private function windowsFrom(Request $request): ?array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_array($payload['windows'] ?? null)) {
            return null;
        }

        $windows = [];
        foreach ($payload['windows'] as $window) {
            if (!is_array($window)) {
                return null;
            }
            $startsAt = self::date($window['startsAt'] ?? null);
            $endsAt = self::date($window['endsAt'] ?? null);
            if (null === $startsAt || null === $endsAt) {
                return null;
            }
            $windows[] = [$startsAt, $endsAt];
        }

        return $windows;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }

        try {
            // Stored to the minute: the seconds of a picker never matter for an arrival.
            $date = new \DateTimeImmutable($value);

            return $date->setTime((int) $date->format('H'), (int) $date->format('i'));
        } catch (\Exception) {
            return null;
        }
    }
}
