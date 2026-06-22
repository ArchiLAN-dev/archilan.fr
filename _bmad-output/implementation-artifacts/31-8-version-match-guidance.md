# Story 31.8: Version-match guidance (apworld + Archipelago client)

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player following a game's install tutorial,
I want to see the **exact apworld version** and the **Archipelago client version** I must install to match
the session,
so that my setup actually connects - version mismatch is the #1 cause of failed Archipelago multiworld
joins, and a tutorial that doesn't pin versions still leaves me stuck.

Cross-cutting story of Epic 31: it makes the install tutorial *correct*, not just present. It reuses the
per-game apworld data we already store and adds a single global "Archipelago client" version/download.

## Acceptance Criteria

1. **Global Archipelago client info.** A single admin-editable record holds the **Archipelago client
   (launcher) version** and its **download URL**. `GET` exposes it publicly; an admin endpoint updates it.
   Empty/unset is handled (guidance degrades gracefully, no broken UI).
2. **apworld version pinned (per game).** The tutorial's `apworld` step surfaces the **deployed** apworld
   version (`apworldDeployedVersion`) as the **version à installer**, with the matching **release link**
   (`apworldReleaseUrl` when present, else `apworldSourceUrl`). When no deployed version is known, show an
   explicit "version non figée - prends la dernière compatible" fallback rather than nothing.
3. **Client version in the generic guide.** The generic "Installer Archipelago" guide (31.3) shows the
   pinned client version + launcher download from AC1.
4. **Version-match guidance.** A clear, reusable callout on the game tutorial states that the player's
   apworld **and** Archipelago client versions must match the session's, or the connection/generation
   fails - shown whenever a required version is known.
5. **Public payload.** `GET /api/v1/games/{slug}` exposes the required apworld version + release link
   (already in the 28.9 `apworld` block - ensure `deployedVersion` + a resolved download link are
   present) and the page can read the global client info (AC1) for the client-version callout.
6. **Admin surface.** Admins can edit the global client version + download URL (small field group in an
   appropriate admin screen). Admin-gated; `ROLE_MEMBER` never used (AC-M1).
7. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **Global client info (api/)** (AC: 1, 6)
  - [ ] **Decision (pinned):** a **minimal dedicated single-row entity** `ArchipelagoClientInfo`
        (`{ version: string, downloadUrl: string, updatedAt }`) in `GameSelection/Domain` (keeps it with
        the rest of the catalog/tutorial data), with a `...RepositoryInterface`. Do **not** build a generic
        settings framework. (Only reuse `SessionConfig` instead if it already exposes a generic
        key/value store - verify; if not, the dedicated entity wins.)
  - [ ] Public read query (DTO) + admin update command (validate URL, trim version).
  - [ ] `GET /api/v1/archipelago-client` (public) and an admin update endpoint (admin-gated).
- [ ] **apworld version in the tutorial (api/)** (AC: 2, 5)
  - [ ] Ensure the public `GET /api/v1/games/{slug}` `apworld` block carries `deployedVersion` and a
        resolved **download link** (prefer `releaseUrl`, else `sourceUrl`). The block already exists from
        28.9 - extend the resolution if the download link is missing.
- [ ] **Frontend** (AC: 2, 3, 4)
  - [ ] On `/jeux/[slug]`, enrich the rendered `apworld` step (31.2): show "Version à installer : {x}" +
        the release link, or the fallback copy when unknown.
  - [ ] A reusable `VersionMatchCallout` component (tokens-only) used on the tutorial; show the client
        version (from `GET /api/v1/archipelago-client`) + the per-game apworld version + the "doit
        correspondre à la session" warning.
  - [ ] Generic guide (31.3) renders the client version + launcher download.
  - [ ] Admin field group to edit the client version + download URL.
- [ ] **Tests** (AC: 7)
  - [ ] Backend functional: public client-info read; admin update (admin-gated, validation); the game
        detail `apworld` block exposes `deployedVersion` + a download link.
  - [ ] Backend unit: client-info update validation; download-link resolution (release vs source).
  - [ ] Frontend jest: client-info API guard; `VersionMatchCallout` renders the right copy for
        known/unknown version states.

## Dev Notes

### Why this is primordial
- Archipelago multiworld **requires version parity** between each player's apworld/client and the host.
  A mismatch fails generation or connection - the most common day-of failure. Pinning the version in the
  tutorial is the difference between "looks complete" and "actually works".

### Reuse, don't reinvent
- **Per-game version data already exists**: `apworldDeployedVersion`, `apworldLatestVersion`,
  `apworldReleaseUrl`, `apworldSourceUrl`, `updateStatus` on `GameCatalogSync`, already surfaced in the
  public `apworld` block (28.9). This story mostly *renders* it prominently + adds the client info.
  [Source: api/src/GameSelection/Domain/GameCatalogSync.php, api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php (bySlug), _bmad-output/implementation-artifacts/28-9-public-game-detail-page.md]
- **Single-record admin setting**: pinned to a dedicated `ArchipelagoClientInfo` entity in
  `GameSelection/Domain` (see Tasks). Only fall back to `SessionConfig` (Epic 27) if it already exposes a
  generic key/value store - do not invent a settings framework here. [Source: _bmad-output/planning-artifacts/epics/epic-27-configurable-session-server-options.md]

### Architecture guardrails
- DDD: read via Application query interface + DBAL Infrastructure impl; admin update via a command
  service + Domain repository interface; no `EntityManager`/`Connection` in Application/controllers.
  Controller = one Application call.
- Frontend: no `as` at the boundary (guards), env via `src/lib/env.ts`, tokens-only, admin-gated by the
  admin shell.

### Scope boundaries / deferred
- **Per-session version override is deferred.** This story pins the **currently deployed** apworld version
  (what the host runs by default) + the global client version. A future refinement can surface a
  session-specific required version on the event/run/connection pages (the "connect step tied to the live
  session" idea) - out of scope here.
- No automatic version detection on the player's machine; guidance is informational.

### Dependencies
- Renders inside the apworld step (31.1 model / 31.2 public render) and the generic guide (31.3) - sequence
  after them. The global client info itself can be built independently.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior art (apworld block): [Source: _bmad-output/implementation-artifacts/28-9-public-game-detail-page.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Implemented on branch `feature/epic-31-story-8-version-match` (from develop).
- Pinned dedicated single-row entity `ArchipelagoClientInfo` (GameSelection/Domain, id `default`) + repo interface + Doctrine impl + migration. Application `ArchipelagoClientQuery` (read) + `UpdateArchipelagoClient` (validates http(s) url). Public `GET /api/v1/archipelago-client`; admin `PUT /api/v1/admin/archipelago-client` (admin-gated via `ApiAccessGuard::requireAdmin`).
- Per-game apworld version + download already in the `apworld` block (28.9); no backend change needed there.
- Frontend: `archipelago-client-api.ts` (`getArchipelagoClient` + guard, `saveArchipelagoClient`); `VersionMatchCallout` on `/jeux/[slug]` (required apworld version + download - release else source - or "version non figée"; client version + download; "doit correspondre à la session" warning); page fetches the client server-side and passes it to `GameDetail`.
- Admin editing surfaced on `/admin/catalogue` via `ArchipelagoClientSettings` (no new nav entry).
- Generic-guide rendering (AC3) deferred with story 31.3 (the guide does not exist yet); the client version is surfaced via the callout instead.
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, phpunit 1272 (+5); FE typecheck/lint/build, jest 47.

### File List

**Added (api)**
- `api/src/GameSelection/Domain/ArchipelagoClientInfo.php`
- `api/src/GameSelection/Domain/ArchipelagoClientInfoRepositoryInterface.php`
- `api/src/GameSelection/Application/ArchipelagoClientQuery.php`
- `api/src/GameSelection/Application/UpdateArchipelagoClient.php`
- `api/src/GameSelection/Infrastructure/DoctrineArchipelagoClientInfoRepository.php`
- `api/src/GameSelection/Presentation/ArchipelagoClientController.php`
- `api/src/GameSelection/Presentation/AdminArchipelagoClientController.php`
- `api/migrations/Version20260619100000.php`
- `api/tests/Functional/ArchipelagoClientTest.php`

**Modified (api)**
- `api/config/services.yaml` (repository alias)

**Added (frontend)**
- `frontend/src/features/games/archipelago-client-api.ts` (+ test)
- `frontend/src/features/admin/archipelago-client-settings.tsx`

**Modified (frontend)**
- `frontend/src/features/games/game-detail.tsx` (VersionMatchCallout + client prop)
- `frontend/src/app/(public)/jeux/[slug]/page.tsx` (fetch + pass client)
- `frontend/src/features/admin/admin-catalogue-sync-page.tsx` (mount settings)

### File List
