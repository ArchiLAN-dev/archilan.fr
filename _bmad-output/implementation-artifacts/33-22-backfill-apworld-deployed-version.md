# Story 33.22: Backfill APWorld Deployed Version by Hash Match (api/)

Status: ready-for-dev

## Story

As an ArchiLAN admin,
I want a one-shot console command that infers each GitHub-tracked game's deployed APWorld version by matching its stored SHA-256 against the repo's release assets,
so that games configured before version tracking (or by direct upload) stop showing "Version inconnue" and the update-status column becomes trustworthy without hand-typing every version.

## Context

`GameCatalogSync.apworldDeployedVersion` (nullable string) is the version *currently deployed*, distinct from
`apworldLatestVersion` (latest GitHub release, populated by `app:check-apworld-updates`). Today
`deployedVersion` is written in only two places: `AdminGameLibrary::importFromGithub()` (auto-stamps the release
tag it just pulled) and the admin edit form (manual entry). Games configured by **direct `.apworld` upload**
(`AdminGameLibrary::configureApworld()` never touches it) or added **before tracking existed** have it `null`.
Per `ApworldUpdateStatus::compute()`, a null deployed version on a GitHub-tracked game yields
`update_status = unknown` → the admin UI (`admin-game-editor.tsx`) renders the grey **"Version inconnue"** badge
with only a "Dernier:" line. That is what "beaucoup sont manquantes" is.

**Why hash-matching is viable (verified 2026-07-11).** `apworldHash` is `sha256(rawbytes)` computed by the
runner over the *exact uploaded file* (`runner/app/apworld_storage.py:16`, `runner/app/main.py:138-141`), stored
verbatim; `ApworldVersionChecker::downloadAsset()` returns the raw GitHub asset bytes. The existing
`importFromGithub` flow already round-trips these same bytes, so for any game whose `.apworld` came from a GitHub
release asset, `sha256(downloadAsset(...))` **equals** the stored `apworldHash`. There is **no version stored
inside** the archive (the zip is only opened to read the game name), so a release-asset hash match is the only
content-based way to recover the version.

**Behaviour boundary.** This is a one-shot data backfill: it changes stored data (fills a null column) but adds
**no product behaviour** - the admin display logic, the status computation, and the update flow are untouched.
It qualifies as epic-33 housekeeping (a data defect: the column should have been populated at deploy time).

### Known limitation (must be logged, not hidden)

Hash matching is **byte-exact**. It will resolve games imported through the GitHub flow and will produce
**false negatives - never false positives** - for `.apworld` files that were hand-built or re-zipped (ZIP is not
canonical: different compression level / file order / timestamps → different bytes → different SHA-256, even for
the "same" world). The command therefore **reports every unmatched game** so the admin knows which ones still
need a manual version. A SHA-256 collision producing a false positive is not a practical concern.

## Acceptance Criteria

1. **AC1 - Command exists, targets the right set, dry-run first.** `app:backfill-apworld-deployed-version` (in
   `CatalogSync/Presentation/Command/`) processes exactly the games where
   `getCatalogSync()?->getApworldSourceUrl()` starts with `https://github.com/` **AND** `apworldHash` is non-null
   **AND** `apworldDeployedVersion` is null. A `--dry-run` option resolves and reports matches **without
   persisting**. Default (no flag) persists.
2. **AC2 - Hash → tag resolution across ALL releases.** For each target game the command builds a
   `sha256 → normalized-tag` map from **every** `.apworld` asset across **all** the source repo's releases (not
   just the latest - a game may sit on an old version), applying the same `?q=` release/asset filter the checker
   uses. If the game's `apworldHash` is in the map, its tag becomes the deployed version via
   `GameCatalogSync::recordApworldDeployment($tag)` (which normalizes the `v`/`V` prefix, matching
   `recordApworldCheck`). One flush at the end (mirrors `CheckApworldUpdatesService`).
3. **AC3 - Efficiency: download each asset once per repo.** Games are grouped by (owner, repo) so a repo's
   release assets are enumerated and hashed **once**, then matched against every target game pointing at that
   repo. Assets are downloaded at most once each; the built map is reused within the run.
4. **AC4 - Unmatched games reported, never guessed.** Every target game whose hash matches nothing is listed in
   the command output (name + hash prefix) and logged (`catalog_sync.apworld_backfill_unmatched`); its
   `deployedVersion` stays null. Matched games are counted and logged
   (`catalog_sync.apworld_backfill_matched`, with game + resolved tag). Final summary line:
   matched / unmatched / total.
5. **AC5 - Rate-limit safe.** GitHub rate-limit handling reuses the existing
   `GithubRateLimitException` / remaining-header mechanism: on hitting the limit the batch stops early, flushes
   what it resolved so far (unless `--dry-run`), and reports "rate limit reached, stopped early" like
   `app:check-apworld-updates`.
6. **AC6 - All gates green; zero behaviour change to the app.** `composer gates` green (phpstan level max,
   cs-fixer src+tests, `app:architecture:ddd`, full phpunit on an isolated DB). Unit tests cover the
   resolver/service; no existing test changes behaviour.

## Design

A thin command over a new application service, cloning the `CheckApworldUpdatesCommand` +
`CheckApworldUpdatesService` shape exactly.

- **`CatalogSync/Presentation/Command/BackfillApworldDeployedVersionCommand.php`** - `#[AsCommand]`, one
  `--dry-run` `InputOption`, delegates to the service, prints the summary + the unmatched list. No logic.
- **`CatalogSync/Application/Command/BackfillApworldDeployedVersionService.php`** (`final readonly`) -
  `backfill(bool $dryRun): array` returning `{matched, unmatched, total, unmatchedGames: list<string>, rateLimitHit}`.
  1. Load games via `GameRepositoryInterface::findAllSortedByName()`, filter to the AC1 target set.
  2. Group targets by (owner, repo) parsed from the source URL (+ the `q` filter term).
  3. Per group: build the `sha256 → tag` map (new checker method, below), catching
     `GithubRateLimitException` to stop early.
  4. Per game in the group: look up `strtolower($game->getApworldHash())` in the map; on hit call
     `$game->getCatalogSync()?->recordApworldDeployment($tag)` (unless `$dryRun`); on miss record it as unmatched.
  5. `flush()` once at the end unless `$dryRun`.
- **New method on `ApworldVersionChecker`** (Application/Service): the existing `findLatestReleaseWithApworld`
  returns only the *first* matching release, so it cannot see older deployed versions. Add
  `mapApworldAssetHashesByTag(Game $game): array` (or a `(owner, repo, filterTerm)` overload the service can call
  once per group) that paginates **all** releases (reuse the `for ($page = 1; $page <= 10; …)` + `per_page=100`
  loop and `githubHeaders()`), and for each non-draft release's `.apworld` assets - honoring the same `$q`
  filter as `findLatestReleaseWithApworld` - downloads the asset via `downloadAsset()`, computes
  `hash('sha256', $bytes)`, and maps it to the release's normalized tag (`ltrim($tag, 'vV')`). Returns
  `array<string, string>` (hash → tag). It must surface/propagate the rate-limit signal the same way
  (`checkRateLimit` on the remaining header) so the service can stop early. Keep the pure-HTTP work in the
  checker (Infrastructure-facing) - the service stays orchestration-only (AC-A rules).

### Cost note (log it)

Unlike `check-apworld-updates` (metadata only), this **downloads every `.apworld` asset of every target repo**
once. For repos with many releases that is real bandwidth + rate-limit budget. That is why AC3 groups by repo
(download-once) and AC5 stops cleanly on the limit; a repeated run resumes naturally because resolved games drop
out of the AC1 target set (deployedVersion no longer null).

## Tasks / Subtasks

- [ ] Task 1: Confirm the target-set predicate against the tree and count the affected rows first (a read-only
  DBAL/console count of GitHub-source games with `apworld_hash IS NOT NULL AND apworld_deployed_version IS NULL`),
  so the run's scope and download cost are known before AC2 work (AC: 1)
- [ ] Task 2: Add `ApworldVersionChecker::mapApworldAssetHashesByTag(...)` - paginate all releases, filter, download +
  `hash('sha256', …)` each `.apworld` asset, return `hash → normalizedTag`; reuse `githubHeaders()`,
  `parseSourceUrl()`, the pagination loop and `checkRateLimit()`. Unit test with a mocked `HttpClientInterface`:
  multi-release paging, `q` filter, and that a known byte string maps to the expected tag (AC: 2, 5)
- [ ] Task 3: `BackfillApworldDeployedVersionService::backfill(bool $dryRun)` - target filter, group-by-repo,
  per-game hash lookup, `recordApworldDeployment` on hit, unmatched collection, single flush, rate-limit
  early-stop. Unit-tested with a stub checker + a `GameRepositoryInterface` mock: matched game gets the tag,
  unmatched stays null, `--dry-run` persists nothing, rate-limit stops early (AC: 2, 3, 4, 5)
- [ ] Task 4: `BackfillApworldDeployedVersionCommand` with `--dry-run`; prints `matched / unmatched / total` and
  the unmatched game list; logs `catalog_sync.apworld_backfill_matched` / `_unmatched` (AC: 1, 4)
- [ ] Task 5: `composer gates` on an isolated DB; run `--dry-run` against real data to eyeball the match rate,
  then PR to develop (AC: 6)

## Dev Notes

- **Reuse, do not reinvent.** `downloadAsset()`, `parseSourceUrl()`, `githubHeaders()`, `checkRateLimit()`,
  `GithubRateLimitException`, and the `findAllSortedByName()` + end-flush pattern already exist. The command/service
  pair is a near-copy of `CheckApworldUpdatesCommand` / `CheckApworldUpdatesService` - match their structure.
  `DoctrineGameRepository::findByApworldHash()` also exists but is per-hash; the group-by-repo map is the efficient
  path here, so prefer building the map over N repository round-trips.
- **The setter is `recordApworldDeployment` (33.16), not `setApworldDeployedVersion`.** Story 33.16 renamed the
  Domain setter to the business method `GameCatalogSync::recordApworldDeployment(?string $version)`, which already
  does `ltrim($version, 'vV')`. Do **not** re-add a setter; do **not** double-normalize.
- **Hash case.** Store/compare lowercase hex. `hashlib.sha256().hexdigest()` (runner) and PHP `hash('sha256', …)`
  both yield lowercase hex; still normalize with `strtolower()` on the stored `apworldHash` before the map lookup to
  be safe.
- **Only the latest release is not enough.** This is the one substantive deviation from the existing checker:
  `findLatestReleaseWithApworld` short-circuits on the first match. The backfill must scan *all* releases because a
  game can be deployed on an old tag - that is the whole point of matching by content rather than assuming latest.
- **No behaviour change to the app.** Do not touch `ApworldUpdateStatus`, `admin-game-editor.tsx`, the status
  computation, or `importFromGithub`. This story only *populates* the existing column.
- **Windows execution lessons apply** (memory: sed/backslash replaces are silent no-ops in PowerShell) - use the
  Edit/Write tools per file; verify with `grep -rn` only.
- **DDD placement.** Command service in `Application/Command/` (a write, returns a summary array to the command -
  acceptable for a console reporter; keep the persisting unit-of-work single, flush once). HTTP/download logic stays
  in `ApworldVersionChecker` (already the HTTP boundary). Console command in `Presentation/Command/`. No new
  context, no `services.yaml` change beyond autowiring the new classes (both live under already-scanned dirs).

### Project Structure Notes

- Files: `api/src/CatalogSync/Presentation/Command/BackfillApworldDeployedVersionCommand.php`,
  `api/src/CatalogSync/Application/Command/BackfillApworldDeployedVersionService.php`; new public method on
  `api/src/CatalogSync/Application/Service/ApworldVersionChecker.php`.
- Tests: `api/tests/Unit/CatalogSync/…` mirroring existing `ApworldVersionChecker` / `CheckApworldUpdatesService`
  unit tests (mock `HttpClientInterface`, stub the checker, mock `GameRepositoryInterface`). No functional test
  needed - the command is a thin reporter over unit-tested services (AC-T "what NOT to test").

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-33-cleanup-and-standards-hardening.md` (follow-up backlog;
  no-behaviour-change / housekeeping charter)
- Version fields: `api/src/GameSelection/Domain/Entity/GameCatalogSync.php` (`apworldDeployedVersion`,
  `apworldLatestVersion`, `recordApworldDeployment()`, `recordApworldCheck()`); passthrough getters
  `api/src/GameSelection/Domain/Entity/Game.php` (`getApworldDeployedVersion`, `getApworldHash`,
  `getApworldSourceUrl`)
- Status policy: `api/src/GameSelection/Domain/ValueObject/ApworldUpdateStatus.php` (null deployed → `unknown`)
- Reuse targets: `api/src/CatalogSync/Application/Service/ApworldVersionChecker.php`
  (`downloadAsset`, `parseSourceUrl`, `githubHeaders`, `findLatestReleaseWithApworld`, `checkRateLimit`),
  `api/src/CatalogSync/Application/Command/CheckApworldUpdatesService.php`,
  `api/src/CatalogSync/Presentation/Command/CheckApworldUpdatesCommand.php`
- Hash origin (byte-exact proof): `runner/app/apworld_storage.py:16` (`sha256(file_bytes).hexdigest()`),
  `runner/app/main.py:138-141`; deployed-version write path `AdminGameLibrary::importFromGithub()`
- Setter rename: `_bmad-output/implementation-artifacts/33-16-domain-setters-business-methods.md`
  (`setApworldDeployedVersion` → `recordApworldDeployment`)
- Frontend "Version inconnue" render: `frontend/src/features/admin/admin-game-editor.tsx`

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-11 | Story created (epic-33 follow-up 33.22). Backfill of `apworld_deployed_version` via SHA-256 match of the stored `apworldHash` against every release asset of the source repo. Feasibility verified byte-exact (runner hashes raw upload bytes; `downloadAsset` returns raw bytes; existing import flow already round-trips them). New `app:backfill-apworld-deployed-version` command + service cloning the check-updates pair; new all-releases hash-map method on `ApworldVersionChecker` (existing checker only sees the latest release). Byte-exact ⇒ false negatives only; unmatched games reported, never guessed. `--dry-run`, group-by-repo download-once, rate-limit early-stop. Status: ready-for-dev. |
