# Story 9.16: Run Archival and Statistics

Status: review

## Story

As an admin or player,
I want the session's spoiler log, save file, and statistics archived when the run ends,
So that results are preserved and publicly accessible as history.

## Acceptance Criteria

1. When a Run transitions to `finished` (force-end from Story 9.15 or all-GOAL detection), the API dispatches an `ArchiveRunJob{sessionId}` via Messenger to the `run_server` queue.
2. `ArchiveRunJob` handler on runner: copies `.apsave` from save volume to a permanent archive location (configurable via env `ARCHIVE_DIR`); copies spoiler log (`.archipelago` file in output volume) if present.
3. Before stopping the container, handler calls `GET http://{bridgeHost}:{bridgePort}/state` (Bridge.py) to get final player aggregate; on timeout/error falls back to parsing the `.apsave` file directly.
4. Handler POSTs an archive callback to central API: `{status: "archived", archived_save_path: "...", archived_spoiler_path: "...|null", slots: [{slot_name, checks_done, items_received, goal_reached_at}]}`.
5. Callback handler stores on `Session`: `archived_save_path`, `archived_spoiler_path`; stores on each matching `SessionSlot`: `checks_done`, `items_received`, `goal_reached_at`.
6. `SessionSlot` entity gets new fields: `checks_done (int, default 0)`, `items_received (int, default 0)`, `goal_reached_at (datetimetz_immutable, nullable)`.
7. Public results page at `/evenements/{slug}/session/resultats` - no auth required; shows the most recent `finished` session: run duration, ranked slot list (GOAL slots by `goal_reached_at` asc, non-GOAL by `checks_done` desc), checks and items per slot.
8. If multiple finished sessions exist for the event, an admin-visible session history selector allows navigation; the public page always shows the most recent finished session.
9. Admins can download spoiler log and `.apsave` via authenticated download endpoints: `GET /api/v1/admin/sessions/{id}/download/spoiler` and `GET /api/v1/admin/sessions/{id}/download/save`.
10. `GET /api/v1/admin/sessions/{id}/export?format=json` and `?format=csv` - admin only; returns per-slot stats.
11. Functional tests: `ArchiveRunJob` dispatched on finished transition; archive callback stores paths + slot stats; public results page accessible without auth; export JSON/CSV format.

## Tasks / Subtasks

- [x] Task 1: Add stats fields to `SessionSlot` entity (AC: #6)
  - [x] Add to `src/Sessions/Domain/SessionSlot.php`:
    - `#[ORM\Column(type: Types::INTEGER)] private int $checksDone = 0`
    - `#[ORM\Column(type: Types::INTEGER)] private int $itemsReceived = 0`
    - `#[ORM\Column(type: 'datetimetz_immutable', nullable: true)] private ?\DateTimeImmutable $goalReachedAt = null`
  - [x] Add getters + setters, include in `payload()`
  - [x] No migration needed - Jean handles migrations

- [x] Task 2: Add archive path columns to `Session` entity (AC: #5)
  - [x] Add `#[ORM\Column(type: Types::STRING, length: 512, nullable: true)] private ?string $archivedSavePath`
  - [x] Add `#[ORM\Column(type: Types::STRING, length: 512, nullable: true)] private ?string $archivedSpoilerPath`
  - [x] Add getters + setters, include in `payload()`

- [x] Task 3: Implement `ArchiveRunJob` handler on runner (AC: #2, #3, #4)
  - [x] `src/Sessions/Application/Handler/ArchiveRunJobHandler.php`
  - [x] Constructor: `RunnerCallbackClient`, `LoggerInterface`, `HttpClientInterface`, `string $runnerId`, `string $workspaceDir`, `string $archiveDir` (from env `ARCHIVE_DIR`)
  - [x] Step 1: Copy files - `.apsave` and `.archipelago` if present
  - [x] Step 2: Fetch player state from Bridge.py `http://localhost:{bridgePort}/state` (3s timeout); on failure → `$slots = []` + log warning
  - [x] Step 3: POST archive callback with results
  - [x] Log each step with `session_id`, `runner_id`

- [x] Task 4: Handle `archived` callback in `SessionLifecycleManager` (AC: #5)
  - [x] Detect `status === "archived"` in `RunnerCallbackController`
  - [x] Store `archived_save_path`, `archived_spoiler_path` on Session
  - [x] For each slot in `callback['slots']`: find `SessionSlot` by `slotName`; update `checksDone`, `itemsReceived`, `goalReachedAt`; flush

- [x] Task 5: Dispatch `ArchiveRunJob` on `finished` transition (AC: #1)
  - [x] `ForceEndController` dispatches `new ArchiveRunJob($id, $bridgePort)`
  - [x] Added `AllGoalController` at `POST /api/v1/internal/sessions/{id}/all-goal` (X-Internal-Secret auth) - transitions to finished, dispatches StopRunJob + ArchiveRunJob, writes audit log with `bridge-system` actor

- [x] Task 6: Public results page (AC: #7, #8)
  - [x] API endpoint `GET /api/v1/events/{eventId}/session/results` - no auth; returns most recent finished session + ranked slots
  - [x] Frontend page `frontend/src/app/(public)/evenements/[eventSlug]/resultats/page.tsx`
  - [x] Page renders: run duration, ranked slot table (GOAL sorted by `goal_reached_at` asc, others by `checks_done` desc)

- [x] Task 7: Download endpoints (AC: #9)
  - [x] `GET /api/v1/admin/sessions/{id}/download/spoiler` - admin only; BinaryFileResponse
  - [x] `GET /api/v1/admin/sessions/{id}/download/save` - same pattern for `.apsave`
  - [x] Return 404 if path is null or file doesn't exist

- [x] Task 8: Export endpoints (AC: #10)
  - [x] `GET /api/v1/admin/sessions/{id}/export?format=json` - returns `[{slot_name, player, game, checks_done, items_received, goal_reached_at}]`
  - [x] `GET /api/v1/admin/sessions/{id}/export?format=csv` - CSV with headers, `Content-Type: text/csv`, `Content-Disposition: attachment`
  - [x] Admin only; 404 if session not found

- [x] Task 9: Functional tests (AC: #11)
  - [x] `testForceEndDispatchesArchiveRunJob` - force-end → `ArchiveRunJob` in transport
  - [x] `testArchiveCallbackStoresStatsOnSlots` - POST callback → verify `SessionSlot.checksDone`, paths on Session
  - [x] `testPublicResultsRequiresNoAuth` - unauthenticated request → 200 + correct shape
  - [x] `testExportJsonFormat` - verify JSON structure per slot
  - [x] `testExportCsvFormat` - verify CSV headers and `Content-Type: text/csv`

## Dev Notes

### `.apsave` Parsing in PHP (Fallback)

Python's `pickle` format is NOT natively parsable in PHP. Options:
1. **Primary**: call Bridge.py `/state` - this is the authoritative source
2. **Fallback** when Bridge.py is unreachable: log a warning and submit empty stats (don't block archival). The `.apsave` parse in PHP would require a native Python script or a complex pickle parser - not worth implementing. Just accept empty stats on Bridge.py failure.

So in the handler: try Bridge.py → on failure, `$slots = []` and log warning, still proceed with file archival.

### Archive Storage

`ARCHIVE_DIR` env var. Default in `.env`: `/var/archilan/archives`. In tests: `%kernel.project_dir%/var/test-archives`. Create directory if it doesn't exist.

### All-GOAL Detection

When Bridge.py receives a state where all slots have `client_status === 30`, it should notify Symfony. Options:
1. **Simple**: Bridge.py calls `POST {SYMFONY_INTERNAL_URL}/api/v1/internal/sessions/{RUN_ID}/all-goal` with `X-Internal-Secret` header → Symfony force-ends the run
2. **Alternative**: Symfony checks the `/state` endpoint periodically (polling - less elegant)

Recommend option 1: add a simple `AllGoalController` to Symfony that handles this. Define it in this story or as a separate task in Story 9.12.

### Results API Endpoint

`GET /api/v1/events/{eventSlug}/session/results` - public, no auth. Query:
```php
// Find event by slug → get eventId
// Find most recent finished session for this event:
$session = $this->em->createQueryBuilder()
    ->select('s')
    ->from(Session::class, 's')
    ->where('s.eventId = :eventId AND s.status = :status')
    ->setParameters(['eventId' => $event->getId(), 'status' => Session::STATUS_FINISHED])
    ->orderBy('s.finishedAt', 'DESC')
    ->setMaxResults(1)
    ->getQuery()->getOneOrNullResult();
```

Then load `SessionSlot` for this session, join with `Registration` to get player display name and `ArchipelagoGame` for game name.

### CSV Output Pattern

```php
$csv = "slot_name,player,game,checks_done,items_received,goal_reached_at\n";
foreach ($slots as $slot) {
    $csv .= sprintf("%s,%s,%s,%d,%d,%s\n", $slot->getSlotName(), ...);
}
return new Response($csv, 200, [
    'Content-Type' => 'text/csv',
    'Content-Disposition' => 'attachment; filename="session-'.$session->getId().'.csv"',
]);
```

### SessionSlot Join for Results

The `SessionSlot` has `registrationId` and `gameId`. To get player name: join `Registration` → join `User`. To get game name: join `ArchipelagoGame`. Use a raw DQL query or multiple lookups.

### References

- `src/Sessions/Domain/Session.php` - add `archived_*` fields, `STATUS_FINISHED` (Story 9.15)
- `src/Sessions/Domain/SessionSlot.php` - add stats fields (this story)
- `src/Sessions/Application/Message/ArchiveRunJob.php` - stub from Story 9.15
- `src/Sessions/Infrastructure/RunnerCallbackClient.php` - `sendCallback()` for handler
- Story 9.15 `ForceEndController` - dispatches `ArchiveRunJob`
- Story 9.12 Bridge.py `/state` - authoritative stats source
- `.env.test` - add `ARCHIVE_DIR=%kernel.project_dir%/var/test-archives`

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

### Completion Notes List

- `ArchiveRunJob` was extended with a `bridgePort: int = 0` field so the handler knows which port to call Bridge.py on; `ForceEndController` and `AllGoalController` both pass the bridgePort.
- Bridge.py is accessed via `http://localhost:{bridgePort}/state` (container port mapped to runner host); on failure, slots default to empty and archival still proceeds.
- The URL segment `[eventSlug]` in the frontend routes is actually the event UUID - events have no slug field. Verified via `getPublicEvent(eventId)` in `public-events-api.ts`.
- `ARCHIVE_DIR` env var added to `.env` (`/var/archilan/archives`) and `.env.test` (`/tmp/archilan-test-archives`); `services.yaml` updated with `$workspaceDir` and `$archiveDir` for `ArchiveRunJobHandler`.
- Full test suite: 492 tests, 4145 assertions (was 487 before this story).

### File List

- `api/src/Sessions/Domain/SessionSlot.php` - stats fields added
- `api/src/Sessions/Domain/Session.php` - archive path fields added
- `api/src/Sessions/Application/Message/ArchiveRunJob.php` - bridgePort field added
- `api/src/Sessions/Application/Handler/ArchiveRunJobHandler.php` - full implementation
- `api/src/Sessions/Application/SessionLifecycleManager.php` - storeArchive() added
- `api/src/Sessions/Presentation/RunnerCallbackController.php` - handles 'archived' status
- `api/src/Sessions/Presentation/AllGoalController.php` - NEW internal all-goal endpoint
- `api/src/Sessions/Presentation/SessionResultsController.php` - NEW public results endpoint
- `api/src/Sessions/Presentation/DownloadController.php` - NEW download endpoints
- `api/src/Sessions/Presentation/ExportController.php` - NEW export endpoints (JSON/CSV)
- `api/config/services.yaml` - ArchiveRunJobHandler args ($workspaceDir, $archiveDir)
- `api/.env` - ARCHIVE_DIR added
- `api/.env.test` - ARCHIVE_DIR added
- `frontend/src/app/(public)/evenements/[eventSlug]/resultats/page.tsx` - NEW public results page
- `api/tests/Functional/AdminRunArchivalTest.php` - NEW 5 functional tests
