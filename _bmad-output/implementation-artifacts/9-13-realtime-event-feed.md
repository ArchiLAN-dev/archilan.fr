# Story 9.13: Real-Time Event Feed

Status: review

## Story

As a confirmed player or admin,
I want to see a real-time feed of Archipelago game events on the session page,
So that I can follow the session's progress without connecting an Archipelago client.

## Acceptance Criteria

1. `GET /api/v1/sessions/{runId}/feed-token` - requires auth (confirmed registrant for this event OR admin); returns a short-lived Mercure subscriber JWT (TTL 1h) scoped to subscribe on topic `runs/{runId}/feed` only, plus the Mercure hub public URL.
2. Non-registrant / unauthenticated callers receive 403 from the endpoint.
3. The Next.js session page subscribes to `runs/{runId}/feed` via `EventSource` using the JWT as `?authorization=...` query parameter (browsers don't support custom headers on EventSource).
4. Each incoming event is prepended to a scrollable feed list: formatted timestamp, message type badge, text content.
5. Message types styled distinctly: `hint` → amber, `item-received` → teal, `location-checked` → blue, `system` → muted gray, `chat` → foreground white.
6. Feed displays at most 100 messages; oldest are removed from the DOM as new ones arrive.
7. On first page load: feed starts empty with a static notice "Les messages apparaîtront en direct" until the first event arrives.
8. If EventSource connection drops: show a disconnected indicator; attempt automatic reconnect after 5 seconds.
9. Feed is accessible from both the player view at `/evenements/{slug}/session` and the admin session management page.
10. Functional tests: feed-token endpoint - confirmed registrant ✅ (200 + JWT), non-registrant ❌ (403), admin ✅ (200 + JWT); JWT has correct topic scope (`runs/{runId}/feed`) and ~1h TTL.

## Tasks / Subtasks

- [x] Task 1: API - `FeedTokenController` (AC: #1, #2, #10)
  - [x] Created `src/Sessions/Presentation/FeedTokenController.php`
  - [x] Route: `GET /api/v1/sessions/{runId}/feed-token`
  - [x] Auth: `requireUser()` then admin check OR confirmed-registrant DQL query
  - [x] Session lookup: 404 if not found
  - [x] Subscriber JWT: `create(subscribe: ["runs/{runId}/feed"], additionalClaims: ['exp' => ...])` (1h TTL)
  - [x] Response: `{"data": {"token": "...", "hubUrl": "...", "topic": "runs/{runId}/feed"}}`
  - [x] Registration check: `status = 'reserved' AND submittedAt IS NOT NULL` (confirmed = YAML submitted)

- [x] Task 2: Functional tests for feed-token (AC: #10)
  - [x] Created `tests/Functional/FeedTokenTest.php`
  - [x] setUp: 3-entity schema (User, Session, Registration)
  - [x] `testFeedTokenRequiresAuth` - 401 without auth
  - [x] `testFeedTokenForbidsNonRegistrant` - player with no registration → 403
  - [x] `testFeedTokenForbidsNonConfirmedRegistrant` - reserved but submittedAt null → 403
  - [x] `testFeedTokenAllowsConfirmedRegistrant` - 200, correct topic in response
  - [x] `testFeedTokenAllowsAdmin` - admin (ROLE_ADMIN) → 200
  - [x] `testFeedTokenReturns404ForUnknownSession` - 404
  - [x] 6 tests, all pass (475/475 total PHP suite)

- [x] Task 3: Frontend - feed component (AC: #3, #4, #5, #6, #7, #8)
  - [x] Created `frontend/src/features/events/event-feed.tsx`
  - [x] On mount: fetches `feed-token` → builds EventSource URL with `?topic=&authorization=`
  - [x] State: `FeedMessage[]` max 100, prepend on each event
  - [x] TypeBadge: hint→amber-500, item-received→teal-500, location-checked→blue-500, system→muted, chat→foreground
  - [x] Empty state: "Les messages apparaîtront en direct"
  - [x] Disconnect: `connected: false` + WifiOff indicator + 5 s reconnect
  - [x] useEffect cleanup: closes EventSource + clears reconnect timer
  - [x] Embedded in `session-connection-gate.tsx` (player session page)

- [x] Task 4: Admin integration (AC: #9)
  - [x] `EventFeed` embedded in `SessionDetail` inside `admin-session-page.tsx`
  - [x] Admin uses same `feed-token` endpoint (admin auth accepted by controller)

## Dev Notes

### Subscriber JWT Pattern

Existing subscriber JWT generation in `src/Realtime/Presentation/RealtimeController.php`:
```php
$token = $this->hub->getFactory()?->create($allowedTopics) ?? '';
```
The first arg to `create()` is the `subscribe` topics list. For feed-token: `create(subscribe: ["runs/{$runId}/feed"])`.

**Different from publisher JWT** (which uses `publish:` arg in `PublisherTokenController`).

### Registration Auth Check

Pattern to verify confirmed registration:
```php
$count = $this->entityManager->createQueryBuilder()
    ->select('COUNT(r.id)')
    ->from(Registration::class, 'r')
    ->where('r.eventId = :eventId AND r.userId = :userId AND r.status = :status')
    ->setParameters(['eventId' => $session->getEventId(), 'userId' => $user->getId(), 'status' => 'confirmed'])
    ->getQuery()->getSingleScalarResult();
if (0 === (int) $count) { return 403; }
```

Check `Registration::class` entity fields - `eventId`, `userId`, `status` - verify field names match the actual entity in `src/Registrations/Domain/Registration.php`.

### EventSource URL

```ts
const url = new URL(hubUrl);
url.searchParams.set('topic', topic);
url.searchParams.set('authorization', token);
const es = new EventSource(url.toString());
```

### Mercure Topic

`runs/{runId}/feed` - Bridge.py publishes here (Story 9.12). The JWT subscriber scope must match exactly.

### Frontend File Locations

- Player session page: check if `frontend/src/app/evenements/[eventSlug]/session/` already exists - create if not
- Admin session page: check `frontend/src/app/admin/evenements/[eventId]/` - reuse/embed feed component
- API client: follow `src/lib/api.ts` pattern (uses `src/lib/env.ts` for base URL - never use `process.env` directly)

### API Response Shape

```json
{
  "data": {
    "token": "<JWT>",
    "hubUrl": "https://hub.archilan.fr/.well-known/mercure",
    "topic": "runs/{runId}/feed"
  }
}
```

### Feed Event Shape (from Bridge.py)

```json
{"type": "hint", "text": "Alice → Bob: Sword of Dawn", "color": "yellow", "timestamp": "2026-05-05T14:30:00Z"}
```

Types: `hint`, `item-received`, `location-checked`, `system`, `chat`.

### References

- `src/Sessions/Presentation/PublisherTokenController.php` - publisher JWT pattern (for contrast)
- `src/Realtime/Presentation/RealtimeController.php` - subscriber JWT pattern (model for FeedTokenController)
- `src/Shared/Infrastructure/Http/ApiAccessGuard.php` - `requireAdmin()`, `requireAuth()` helpers
- Story 9.12 Bridge.py - defines feed event payload format
- `.env.test` - `MERCURE_JWT_SECRET=dev_mercure_jwt_secret_change_in_production`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `QueryBuilder::setParameters()` rejects plain PHP arrays in this Doctrine version - must use chained `setParameter()` calls instead.

### Completion Notes List

- "Confirmed registrant" = `status = 'reserved' AND submittedAt IS NOT NULL` (not a separate `STATUS_CONFIRMED` constant - `confirm()` only sets `submittedAt`, not `status`).
- `SpyHub` in tests returns `'null-token'` for JWTs and `''` for `getPublicUrl()`. The frontend `EventFeed` guards against empty `hubUrl` by switching to `unavailable` state, so it renders nothing in dev/test when Mercure is not configured.
- TypeScript compiled clean (`tsc --noEmit`) after adding the feed component.
- Feed is embedded at the bottom of the player session view (after slots) and at the bottom of admin `SessionDetail`.

### File List

- `api/src/Sessions/Presentation/FeedTokenController.php` (new)
- `api/tests/Functional/FeedTokenTest.php` (new)
- `frontend/src/features/events/event-feed.tsx` (new)
- `frontend/src/features/events/session-connection-gate.tsx` (modified - imports EventFeed, renders it when session exists)
- `frontend/src/features/admin/admin-session-page.tsx` (modified - imports EventFeed, renders it in SessionDetail)
