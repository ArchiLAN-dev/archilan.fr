# Story 30.28: A community profile is public by default

**Status:** done
**Epic:** 30 - Community
**Date:** 2026-07-22

## Story

As a new member,
I want my profile to be visible by default,
so that the community surface is populated without every member having to find a setting first.

## Context

Profile visibility is the `audience` field on `CommunityProfile` (`public` | `members` | `friends`,
see `Audience`). It defaulted to `members`, so a new account was members-only until its owner went
looking for the setting.

The default was spelled out in **eight** places with no shared constant - the entity property and its
ORM `options.default`, the creating migration, the update handler, two read fallbacks, and two
frontend literals - and **no test asserted it anywhere**. Nothing would have failed if two of those
sites disagreed.

### Decisions

- **`Audience::DEFAULT` is the single source of truth.** Every default site now points at it, and two
  tests assert it. This is what stops the eight copies from drifting again.
- **Existing rows are left untouched.** This is the load-bearing decision, taken by the author.
  Nothing in the data separates a `members` its owner deliberately chose from a `members` that was
  merely the old default: both are the same string. A blanket `UPDATE` would therefore publish the
  profiles of people who had restricted them on purpose.
- **No discriminant exists, and one was looked for.** `create()` sets `updatedAt == createdAt`, so
  "never customised" looked detectable. It is not: `cacheAvatar()` also bumps `updatedAt` and runs
  automatically on the read path when an avatar goes stale, so nearly every existing row has a
  touched timestamp without its owner having decided anything. Recorded here so the idea is not
  retried as a follow-up.
- **A missing profile row stays members-only.** `ProfileVisibility` keeps `?? Audience::MEMBERS`
  rather than the new default. Rows are only created lazily (self view, avatar service, save), so no
  row means an account that never engaged with the profile surface at all. Reading the public default
  there would expose dormant accounts, contradicting the whole point of leaving existing profiles
  alone. The public default applies to rows **as they are created**, not to the absence of a row.
  `editableForUser()` is the exception and uses `Audience::DEFAULT`: it feeds the owner's own settings
  form, which must show what a first save will actually produce.

### Defect found and fixed along the way

`UpdateCommunityProfile` resolved an omitted `audience` to the default:

```php
$audience = is_string($input['audience'] ?? null) ? $input['audience'] : Audience::MEMBERS;
```

`PUT /api/v1/community/profile` is a full replace, so a payload without `audience` silently rewrote
the setting. That was invisible while the default was the most restrictive value - it could only ever
tighten. **With a `public` default the same line publishes the profile of someone who had chosen
Friends only, with no action on their part.** An omitted audience now keeps the stored value; a
profile created during the same request holds the entity default, so a first save without the field
still lands on `Audience::DEFAULT`.

`CommunityProfileCustomizationTest::testShowcaseLayoutIsSavedDedupedAndFiltered` proves the shape was
already reachable: it PUTs `showcaseLayout` alone.

## Acceptance Criteria

1. `Audience::DEFAULT` exists and every default site points at it - entity, ORM default, update
   handler, frontend form.
2. A newly created profile has audience `public`, asserted by a unit test and a functional test.
3. Existing rows are not modified: the migration alters the column DEFAULT only.
4. A `PUT` that omits `audience` preserves the stored value, covered by a test that would fail on the
   old behaviour.
5. A user with no profile row is still treated as members-only by the visibility gate.
6. Gates green on both sides.

## Tasks / Subtasks

- [x] **Task 1 - Constant** (AC 1). `Audience::DEFAULT = self::PUBLIC`; entity, handler and frontend
      point at it. `DEFAULT_AUDIENCE` mirrors it in `community-profile-api.ts`.
- [x] **Task 2 - Migration** (AC 3). `ALTER COLUMN audience SET DEFAULT 'public'`, reversible, no row
      touched.
- [x] **Task 3 - Partial-save fix** (AC 4). Omitted audience resolved from the stored profile.
- [x] **Task 4 - Read fallbacks** (AC 5). `ProfileVisibility` and the public view keep `MEMBERS`;
      `editableForUser()` moves to `DEFAULT`. All three carry a comment saying which and why.
- [x] **Task 5 - Tests** (AC 2, 4). Unit default, functional default, partial-save preservation.

## Dev Notes

- The three `?? Audience::MEMBERS` fallbacks look identical and must not be swept together - two are
  visibility decisions, one is a form pre-fill. Each is commented at the call site.
- Deployment: the migration only changes a DEFAULT, so it is safe to run before or after the new
  version is serving. No backfill, no downtime consideration.

### Project Structure Notes

- `api/src/Community/Domain/ValueObject/Audience.php`, `Domain/Entity/CommunityProfile.php`
- `api/src/Community/Application/Command/UpdateCommunityProfile.php`,
  `Application/Query/CommunityProfileView.php`, `Application/Support/ProfileVisibility.php`
- `api/migrations/Version20260722120000.php`
- `frontend/src/features/community/community-profile-api.ts`,
  `community-profile-customization-form.tsx`

### References

- [Source: api/src/Community/Domain/Service/AudiencePolicy.php] - the viewer-tier decision this feeds

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

- API: phpstan clean, cs-fixer clean, DDD boundaries OK, rector clean, 1588 tests / 10807 assertions
  green on an isolated database.
- Frontend: typecheck, lint, 224 tests, build green.

### File List

- `api/src/Community/Domain/ValueObject/Audience.php`
- `api/src/Community/Domain/Entity/CommunityProfile.php`
- `api/src/Community/Application/Command/UpdateCommunityProfile.php`
- `api/src/Community/Application/Query/CommunityProfileView.php`
- `api/src/Community/Application/Support/ProfileVisibility.php`
- `api/migrations/Version20260722120000.php`
- `api/tests/Unit/Community/CommunityProfileTest.php`
- `api/tests/Functional/CommunityProfileCustomizationTest.php`
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`
