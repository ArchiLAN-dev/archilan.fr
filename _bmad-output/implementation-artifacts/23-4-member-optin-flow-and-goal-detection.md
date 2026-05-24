# Story 23.4: Member Opt-In, On-Demand Launch & Goal Detection

## Story

**As a** member,
**I want** to opt into the weekly run when I'm ready and launch my personal Archipelago session on demand, with my goal progress recorded automatically,
**So that** I can play the week's challenge at my own pace and appear on the leaderboard when I finish.

## Status

done

## Acceptance Criteria

**AC1:** `POST /api/v1/weekly-runs/{weeklyRunId}/entries` (ROLE_MEMBER). Returns `422 { error: 'run_not_active' }` if run status ≠ `'active'`. Returns `422 { error: 'max_attempts_reached' }` if member's entry count ≥ template's `maxAttempts` (skip when `maxAttempts` is null). Creates `WeeklyEntry` with `attemptNumber = existingCount + 1`, all nullable fields null. Persists and flushes. Returns `201 { data: { id, weeklyRunId, userId, attemptNumber } }`. Non-members receive `403`.

**AC2:** `DELETE /api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}` (ROLE_MEMBER, own entry only). Allowed only if `externalSessionId IS NULL` (session not yet started). Returns `204`. Returns `403` if entry belongs to another user. Returns `422 { error: 'session_already_started' }` if `externalSessionId` is set.

**AC3:** `POST /api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/launch` (ROLE_MEMBER, own entry only). Validates: run `status = 'active'`; entry belongs to authenticated user; `externalSessionId IS NULL` (not already launched); returns `422 { error: 'session_already_started' }` if already launched. Fetches: `WeeklyTemplate.yamlConfig`, `WeeklyTemplate.gameId`; DBAL-queries `game.apworld_storage_key` by `gameId`; returns `422 { error: 'game_not_ready' }` if key is null. Generates pre-signed MinIO URL for the APWorld (`MinioStorageInterface::presignedUrl`). DBAL-queries `user.display_name` for the authenticated user. Substitutes `displayName` as the YAML `name` field. Calls `WeeklyRunnerGatewayInterface::launchEntry(entryId, run.seed, apworldStorageKey, apworldDownloadUrl, displayName, substitutedYaml)`. Calls `WeeklyEntry::launch(externalSessionId, now)`. Flushes. Returns `201 { data: { entryId, externalSessionId, connectionInfo: { host, port, password } } }`.

**AC4:** `POST /api/v1/internal/weekly-runs/goal-callback` (X-Internal-Secret header). Body: `{ externalSessionId: string, checksTotal: int, itemsTotal: int, goalReachedAt: string (ISO 8601) }`. Rejects with `401` on bad/missing secret. Looks up `WeeklyEntry` by `externalSessionId`. If not found: logs warning, returns `200` (not an error - runner may retry on non-200). If `goalReachedAt` already set: no-op, returns `200`. Otherwise: calls `WeeklyEntry::recordGoal(goalReachedAt, completionTimeSeconds, checksTotal, itemsTotal)` where `completionTimeSeconds = max(0, goalReachedAt - run.startedAt)`. Flushes. Dispatches Mercure publish on `weekly-runs/{weeklyRunId}/leaderboard`. Returns `200 { data: { entryId } }`.

**AC5:** `GET /api/v1/weekly-runs/{weeklyRunId}/leaderboard` (public). Returns `{ data: { fastest: [...], fewestChecks: [...], fewestItems: [...], participants: [...] } }`. Goal-only entries in leaderboards ordered by metric ASC. `participants` contains all entries with `userId`, `displayName`, `attemptNumber`, `goalReachedAt`. `displayName` joined via DBAL from `user` table.

**AC6:** `GET /api/v1/weekly-runs/current` (public - no auth required, optional JWT cookie). Returns `200 { data: [{ weeklyRunId, templateName, gameName, weekNumber, weekYear, status, startedAt, finishedAt, leaderboard: { fastest: [...], fewestChecks: [...], fewestItems: [...] }, participants: [...], myEntry: null | { entryId, launchedAt, goalReachedAt, connectionInfo: { host, port, password } | null } }] }`. `gameName` joined from `game` table (DBAL). `myEntry` is `null` for unauthenticated callers and for authenticated members who have not opted in; when the member has opted in, `connectionInfo` is `null` until launch, then populated. `displayName` in participants joined from `user` table (DBAL).

**AC7:** Functional tests cover all of the following (13 scenarios):
- opt-in creates entry (201)
- maxAttempts=1 blocks second opt-in (422 `max_attempts_reached`)
- opt-in on inactive run returns 422 `run_not_active`
- withdraw succeeds before launch (204)
- withdraw blocked after launch (422 `session_already_started`)
- withdraw on another user's entry returns 403
- launch substitutes displayName in YAML (assert gateway receives substituted YAML)
- launch on already-launched entry returns 422
- goal callback with bad/missing `X-Internal-Secret` returns 401
- goal callback records stats and dispatches Mercure publish
- idempotent goal callback (second call with same sessionId) is a no-op, returns 200
- `GET /current` returns `myEntry` when authenticated, `null` when anonymous
- leaderboard returns three sorted arrays (fastest, fewest checks, fewest items)

All four quality gates pass.

## Tasks / Subtasks

- [ ] Task 1: Create `OptInToWeeklyRun` service + `WeeklyRunOptInController`
- [ ] Task 2: Create `WithdrawFromWeeklyRun` service + controller action (DELETE)
- [ ] Task 3: Create `LaunchWeeklyEntry` service + `WeeklyRunLaunchController`
- [ ] Task 4: Create `RecordWeeklyGoal` service + `WeeklyGoalCallbackController`
- [ ] Task 5: Create `WeeklyRunLeaderboardQuery` + `WeeklyRunLeaderboardController`
- [ ] Task 6: Create `CurrentWeeklyRunsQuery` + `CurrentWeeklyRunsController`
- [ ] Task 7: Write functional tests (13 scenarios in AC7)
- [ ] Task 8: Run all four quality gates

## Dev Notes

### LaunchWeeklyEntry - YAML name substitution

The template YAML has "ArchiLAN" as the `name` field. At launch time, substitute the player's real `displayName`:

```php
use Symfony\Component\Yaml\Yaml;

$parsed = Yaml::parse($template->getYamlConfig());
$parsed['name'] = $displayName; // override top-level 'name' key
$substitutedYaml = Yaml::dump($parsed, 4, 2);
```

The substituted YAML is passed to `WeeklyRunnerGatewayInterface::launchEntry()` and is never stored - `WeeklyTemplate.yamlConfig` always keeps the original admin config with "ArchiLAN".

### LaunchWeeklyEntry - cross-context DBAL reads + APWorld download URL

Three steps before calling the gateway. `LaunchWeeklyEntry` injects `MinioStorageInterface $minioStorage`, `string $minioApworldsBucket`, and `int $minioPresignTtl` (follow the pattern in `SessionOrchestrator`):

```php
// 1. Get apworld storage key from game table (GameSelection context)
$gameRow = $this->connection->createQueryBuilder()
    ->select('g.apworld_storage_key AS apworldStorageKey')
    ->from('game', 'g')
    ->where('g.id = :gameId')
    ->setParameter('gameId', $template->getGameId())
    ->executeQuery()
    ->fetchAssociative();

if (false === $gameRow || null === $gameRow['apworldStorageKey']) {
    // Throw a typed exception; controller catches it and returns 422 { error: 'game_not_ready' }
    throw new \DomainException('game_not_ready');
}

$apworldStorageKey = (string) $gameRow['apworldStorageKey'];

// 2. Generate pre-signed MinIO URL for the APWorld file
//    The runner's write_slot_yamls() only creates apworld_urls.json when apworldDownloadUrl is present.
//    Without this, the generator skips the APWorld download and the world generation will fail.
$apworldDownloadUrl = $this->minioStorage->presignedUrl(
    $this->minioApworldsBucket,
    $apworldStorageKey,
    $this->minioPresignTtl,
);

// 3. Get displayName from user table (Identity context)
$userTable = $this->connection->quoteSingleIdentifier('user');
$userRow = $this->connection->createQueryBuilder()
    ->select('u.display_name AS displayName')
    ->from($userTable, 'u')
    ->where('u.id = :userId')
    ->setParameter('userId', $userId)
    ->executeQuery()
    ->fetchAssociative();

$displayName = (is_array($userRow) && is_string($userRow['displayName'])) ? $userRow['displayName'] : 'ArchiLAN';
```

### Internal Secret authentication

```php
$secret   = $request->headers->get('X-Internal-Secret', '');

if (!hash_equals($this->centralApiSecret, $secret)) {
    return new JsonResponse(['error' => 'unauthorized'], 401);
}
```

`$centralApiSecret` is constructor-injected; `services.yaml` already binds `string $centralApiSecret: '%env(CENTRAL_API_SECRET)%'` globally (line 29). Do not use `$this->getParameter('app.internal_secret')` - that parameter does not exist.

### RecordWeeklyGoal - entry lookup by externalSessionId

```php
$entry = $this->entityManager->getRepository(WeeklyEntry::class)->findOneBy([
    'externalSessionId' => $externalSessionId,
]);
```

Or via DBAL query to avoid loading the full entity graph:

```php
$row = $this->connection->createQueryBuilder()
    ->select('we.id', 'we.weekly_run_id AS weeklyRunId', 'we.goal_reached_at AS goalReachedAt')
    ->from('weekly_entries', 'we')
    ->where('we.external_session_id = :sessionId')
    ->setParameter('sessionId', $externalSessionId)
    ->executeQuery()
    ->fetchAssociative();
```

### completionTimeSeconds - run.startedAt as baseline

`WeeklyRun.startedAt` is set Monday 00:00 UTC (the moment the scheduler creates the run). All players are measured from this same reference, regardless of when they personally launch. A player who launches Wednesday and finishes Thursday has a higher `completionTimeSeconds` than one who launched Monday morning - this is intentional and creates a fair leaderboard (earlier starts are rewarded).

### Mercure publish format

```json
{
  "event": "goal_reached",
  "entryId": "uuid",
  "userId": "uuid",
  "displayName": "PlayerName",
  "completionTimeSeconds": 54321,
  "checksTotal": 42,
  "itemsTotal": 87,
  "goalReachedAt": "2026-05-20T14:32:00+00:00"
}
```

Dispatch via `HubInterface` (autowired from `symfony/mercure`):

```php
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

try {
    $this->mercureHub->publish(new Update(
        topics: [sprintf('weekly-runs/%s/leaderboard', $weeklyRunId)],
        data: json_encode($payload, JSON_THROW_ON_ERROR),
    ));
} catch (\Throwable $e) {
    $this->logger->warning('Mercure publish failed for weekly leaderboard', [
        'weeklyRunId' => $weeklyRunId,
        'error' => $e->getMessage(),
    ]);
}
```

Inject `HubInterface $mercureHub` in the controller or service constructor. Wrap in try-catch so a Mercure failure never blocks the `200` response. Follow the same pattern as `SessionLifecycleManager` in the Sessions context.

### CurrentWeeklyRunsQuery - myEntry

The endpoint checks if the request is authenticated and, if so, includes the caller's `WeeklyEntry` in the response. Use `$this->security->getUser()` in the controller to get the user ID and pass it to the query as an optional parameter. The query does a `LEFT JOIN` on `weekly_entries` filtered by `user_id = :myUserId`. When unauthenticated, `myEntry` is `null`.

### HttpWeeklyRunnerGateway::launchEntry() - implementation hint

The gateway implementation and the new runner endpoint `POST /sessions/{id}/generate-and-launch` are specified in Story 23.2. This story only calls the gateway via the interface. Key points for `LaunchWeeklyEntry`:
- Inject `MinioStorageInterface` + `string $minioApworldsBucket` + `int $minioPresignTtl`
- Call `$this->minioStorage->presignedUrl(...)` before calling `$this->gateway->launchEntry()`
- The gateway call signature is: `launchEntry($entryId, $seed, $storageKey, $downloadUrl, $playerName, $yaml)`
- Controller catches `\DomainException('game_not_ready')` and returns `422 { error: 'game_not_ready' }`

## File List

- `api/src/WeeklyRuns/Application/OptInToWeeklyRun.php` - new
- `api/src/WeeklyRuns/Application/WithdrawFromWeeklyRun.php` - new
- `api/src/WeeklyRuns/Application/LaunchWeeklyEntry.php` - new
- `api/src/WeeklyRuns/Application/RecordWeeklyGoal.php` - new
- `api/src/WeeklyRuns/Application/WeeklyRunLeaderboardQuery.php` - new
- `api/src/WeeklyRuns/Application/CurrentWeeklyRunsQuery.php` - new
- `api/src/WeeklyRuns/Infrastructure/HttpWeeklyRunnerGateway.php` - modified (implement `launchEntry()`, `terminate()`, `getStats()`)
- `api/src/WeeklyRuns/Presentation/WeeklyRunOptInController.php` - new
- `api/src/WeeklyRuns/Presentation/WeeklyRunWithdrawController.php` - new
- `api/src/WeeklyRuns/Presentation/WeeklyRunLaunchController.php` - new
- `api/src/WeeklyRuns/Presentation/WeeklyGoalCallbackController.php` - new
- `api/src/WeeklyRuns/Presentation/WeeklyRunLeaderboardController.php` - new
- `api/src/WeeklyRuns/Presentation/CurrentWeeklyRunsController.php` - new
- `api/tests/Functional/WeeklyRunOptInTest.php` - new
- `api/tests/Functional/WeeklyRunLaunchTest.php` - new
- `api/tests/Functional/WeeklyGoalCallbackTest.php` - new

## Change Log

| Date       | Change                                                                              |
|------------|-------------------------------------------------------------------------------------|
| 2026-05-17 | Story created                                                                       |
| 2026-05-17 | Revised: on-demand per-player launch (POST .../launch endpoint added). Goal callback uses externalSessionId (not slotIndex). YAML name substitution happens in LaunchWeeklyEntry, not scheduler. |
| 2026-05-17 | Revised: internal secret auth corrected to constructor-injected `$centralApiSecret` (not `getParameter`). Mercure publish code specified explicitly (HubInterface, Update, try-catch). |
| 2026-05-17 | Revised: AC6 `/current` response fully specified (all fields, myEntry with connectionInfo). AC7 expanded to 13 scenarios with missing cases (bad secret, wrong owner, game_not_ready, /current with/without auth). |
| 2026-05-17 | Revised: AC3 adds `422 game_not_ready` + `MinioStorageInterface` injection for presigned URL. Dev Notes corrected: `launchEntry()` signature gains `$apworldDownloadUrl`. `\DomainException('game_not_ready')` pattern documented. |
