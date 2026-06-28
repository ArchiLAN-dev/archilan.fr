# Story 2.10: Choose your profile URL (change account slug)

**Status:** review
**Epic:** 2 - Identity & account
**Date:** 2026-06-28

## Story

As a member,
I want to choose my profile URL (`/joueurs/{slug}`) from my profile settings,
so that my public address reflects the name I want instead of the auto-generated one.

## Context

The slug lives on `Identity\User` (unique index), was auto-derived at signup (`SlugGenerator`) and had no
setter - immutable. Crucially, **every relationship is keyed by user id** (friendships, comments, kudos,
achievements, feeds resolve slug→id at request time), so changing a slug has **no internal data-integrity
impact**; only external links to the old `/joueurs/{oldSlug}` break.

Product decisions (confirmed with Jean):
- **Old links**: accept 404 (no history/redirect table).
- **Cooldown**: one change per 30 days. **Reclaiming your own previous slug is exempt** (the undo).
- **Reservation**: a released slug stays reserved 30 days **for its former owner only** (others blocked).
- **Anti-hoarding**: only ONE previous slug is kept per user (`previousSlug`, overwritten) + the cooldown,
  so a user can reserve at most one slug at a time - no hoarding (this is why no history table is used).

## Acceptance Criteria

1. Domain: `User::changeSlug(newSlug, now)` sets the slug, keeps the released one in `previousSlug`, stamps
   `slugChangedAt`. New columns `previous_slug` + `slug_changed_at` (migration).
2. Command `ChangeUserSlug`: validate format → cooldown (skipped when reclaiming `previousSlug`) →
   availability (not taken; not reserved by another, former owner excluded) → `changeSlug` → flush
   (catch unique race). Distinct error codes: `slug_invalid`, `slug_reserved_word`, `slug_taken`,
   `slug_reserved`, `slug_cooldown` (+ nextAllowedAt), `slug_unchanged`.
3. Validation: lowercase `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$`, 3-30 chars, rejects anything the canonical
   slugifier would alter (spaces/accents/punctuation/edge or doubled hyphens), reserved-word list.
4. Endpoint `PUT /api/v1/account/slug`; `GET /api/v1/account/profile` exposes `slug` +
   `nextSlugChangeAllowedAt` (null = can change a new slug now; reclaim always allowed).
5. Frontend: `ProfileSlugEditor` on `/compte/profil` - `/joueurs/` prefixed input, format hint, cooldown
   notice (disabled with the next-allowed date), error messages per code, success update.
6. Gates green: API `phpstan`/`cs-fixer`/`phpunit`/`ddd`; frontend `typecheck`/`lint`/`build`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1). `User` columns + `changeSlug` + getters; migration `Version20260622160001`.
- [x] **Task 2** (AC 2,3). `ChangeUserSlug` command + `sanitize`; `UserRepositoryInterface::isSlugReserved`
  (+ DBAL impl on the connection).
- [x] **Task 3** (AC 4). `PUT /account/slug` on `ProfileController`; `slug`/`nextSlugChangeAllowedAt` in the
  profile payload.
- [x] **Task 4** (AC 5). `ProfileSlugEditor` on the profil page; updated the form's display-name hint.
- [x] **Task 5** (AC 6). Unit (`sanitize`) + functional (success / invalid / reserved-word / taken /
  cooldown+reclaim / reserved-by-other) tests; gates green; **verified live** (change → reclaim round-trip,
  dev account restored).

## Dev Notes

- `isSlugReserved(slug, cutoff, exceptUserId)` excludes the requesting user so the former owner can reclaim
  their own released slug within the window; others are blocked.
- Reclaim is the only cooldown-exempt move and only targets `previousSlug`, so it can't be used to grab new
  slugs rapidly (a normal change still updates `slugChangedAt`).
- "now" uses `new \DateTimeImmutable()` (matches `RegisterUser`); the unique index + caught
  `UniqueConstraintViolationException` cover the race.
- Existing slugs/users are unaffected (`slug_changed_at`/`previous_slug` default NULL → no cooldown).

### Project Structure Notes

- `api/src/Identity/Domain/User.php`, `UserRepositoryInterface.php`, `Infrastructure/DoctrineUserRepository.php`
- `api/src/Identity/Application/ChangeUserSlug.php` (new)
- `api/src/Identity/Presentation/ProfileController.php`
- `api/migrations/Version20260622160001.php` (new)
- `frontend/src/features/auth/profile-slug-editor.tsx` (new), `compte/profil/page.tsx`,
  `community/community-profile-customization-form.tsx` (hint)
- Tests: `api/tests/Unit/Identity/ChangeUserSlugTest.php`, `api/tests/Functional/ProfileSlugTest.php`

### References

- [Source: api/src/Identity/Application/SlugGenerator.php (canonicalization reused by sanitize)]
- [Source: api/src/Identity/Application/RegisterUser.php (now + unique-race pattern)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

- Self-service slug change end to end: domain mutator + `ChangeUserSlug` command (format/cooldown/
  reservation/uniqueness) + `PUT /account/slug` + `ProfileSlugEditor`.
- Cooldown 30 days, reclaim exempt; released slug reserved 30 days for its former owner only; single
  `previousSlug` ⇒ no hoarding. No old-slug redirect (404 accepted).
- Unit + functional tests; all gates green; verified live then restored the dev account.

### File List

- `api/src/Identity/Domain/User.php`
- `api/src/Identity/Domain/UserRepositoryInterface.php`
- `api/src/Identity/Infrastructure/DoctrineUserRepository.php`
- `api/src/Identity/Application/ChangeUserSlug.php`
- `api/src/Identity/Presentation/ProfileController.php`
- `api/migrations/Version20260622160001.php`
- `api/tests/Unit/Identity/ChangeUserSlugTest.php`
- `api/tests/Functional/ProfileSlugTest.php`
- `frontend/src/features/auth/profile-slug-editor.tsx`
- `frontend/src/app/(public)/compte/profil/page.tsx`
- `frontend/src/features/community/community-profile-customization-form.tsx`

### Change Log

| Date       | Change |
|------------|--------|
| 2026-06-28 | Created + implemented. Self-service profile slug change: `User.changeSlug` (+ previous_slug/slug_changed_at migration), `ChangeUserSlug` command (30-day cooldown, reclaim exempt, 30-day reservation for former owner only, single previous-slug ⇒ no hoarding), `PUT /account/slug`, `ProfileSlugEditor`. No old-slug redirect (404). Tests + gates green; verified live. Status → review. |
