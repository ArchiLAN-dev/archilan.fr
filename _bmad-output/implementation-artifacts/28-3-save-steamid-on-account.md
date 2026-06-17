# Story 28.3: Save Steam account on the member profile

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a logged-in user,
I want to save my Steam account on my ArchiLAN profile,
so that the Jeux page re-couples my library automatically without me re-typing it each visit.

This adds **persistence** to Epic 28. It stores the Steam reference on the `User`, exposes it on the profile payload, and gives `/compte` a save/remove control. The coupling itself stays in 28.2; the `/jeux` auto-couple consumption is 28.4.

## Acceptance Criteria

1. `User` persists a **nullable `steam_profile` string** (the raw reference the user entered: a SteamID64, a vanity name, or a profile URL - whatever the coupling endpoint accepts). It is **not unique** (two accounts may reference the same Steam account; that is acceptable). A reversible migration adds the column.
2. `PUT /api/v1/account/steam` (authenticated user) accepts `{ "steamProfile": "<raw>" }`, validates it is **parseable** by `SteamProfileReference::parse` (no network call at save), stores the trimmed raw value, and returns `200 { data: { steamProfile } }`. Unparseable input returns `422 steam_invalid_input`.
3. `DELETE /api/v1/account/steam` (authenticated user) clears the stored value and returns `204`.
4. `GET /api/v1/account/profile` includes `steamProfile` (`string | null`) in its payload.
5. The `/compte` page shows a "Compte Steam" control: when none is saved, an input + "Enregistrer"; when one is saved, the value + "Modifier" / "Retirer". Uses `apiFetch` and updates the in-memory auth user. RGPD: the saved Steam reference is included in account deletion/erasure and mentioned on the privacy page.
6. Backend gates green (`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`); frontend gates green (`pnpm typecheck`, `lint`, `build`).

## Tasks / Subtasks

- [ ] **Domain: `steamProfile` on `User`** (AC: 1)
  - [ ] `api/src/Identity/Domain/User.php`: add constructor property `#[ORM\Column(name: 'steam_profile', type: 'string', length: 190, nullable: true)] private ?string $steamProfile = null` (append at end, like the `discord_*` columns). Add `setSteamProfile(?string $steamProfile): void` (trim, store `null` if empty), `getSteamProfile(): ?string`. Do **not** mark unique.
- [ ] **Migration** (AC: 1)
  - [ ] New `Version20260615######.php` (timestamp after the latest). `up`: `ALTER TABLE "user" ADD COLUMN steam_profile VARCHAR(190) DEFAULT NULL` (quote `"user"` - reserved word in Postgres, as in the existing discord_id migration). `down`: drop column.
- [ ] **Application: save / remove** (AC: 2, 3)
  - [ ] `api/src/Identity/Application/SaveSteamAccount.php` (`final readonly`), inject `UserRepositoryInterface`. `save(string $userId, string $rawInput): array{outcome: 'saved'|'invalid_input'|'not_found'}` - find user (null ⇒ `not_found`); `SteamProfileReference::parse($rawInput)` null ⇒ `invalid_input`; else `$user->setSteamProfile(trim($rawInput)); $repo->save($user);` ⇒ `saved`. `remove(string $userId): void` - find user; `setSteamProfile(null)`; save. Log `info` on both (mirror `LinkDiscordToAccount` logging).
- [ ] **Presentation: account endpoints** (AC: 2, 3)
  - [ ] `api/src/Identity/Presentation/SteamAccountController.php` (`final readonly`, `use RequiresAuthTrait`), inject `ApiAccessGuard`, `SaveSteamAccount`. Mirror `DiscordLinkController` structure.
    - `PUT /api/v1/account/steam`: `requireAuthenticatedUser`; read `steamProfile` (string, trimmed) from JSON body; empty ⇒ `422 steam_invalid_input`; call `save`; map `invalid_input` ⇒ `422`, `saved` ⇒ `200 { data: { steamProfile } }`.
    - `DELETE /api/v1/account/steam`: `requireAuthenticatedUser`; `remove`; `204`.
- [ ] **Expose on profile payload** (AC: 4)
  - [ ] `api/src/Identity/Presentation/ProfileController.php`: add `'steamProfile' => $user->getSteamProfile(),` to `profilePayload()` and to its return-type docblock.
- [ ] **RGPD** (AC: 5)
  - [ ] Ensure account deletion/erasure clears/`steam_profile` with the user row (it cascades with the `user` row delete; if there is an anonymization path that nulls PII columns, add `steam_profile` there). Add a one-line mention to the privacy page (`frontend/src/app/(public)/confidentialite/page.tsx`).
- [ ] **Frontend: account control** (AC: 5)
  - [ ] Add `steamProfile: string | null` to `AuthUser` in `frontend/src/features/auth/auth-context.tsx` (and the profile fetch already populates it).
  - [ ] `frontend/src/features/auth/steam-account-api.ts`: `saveSteamAccount(steamProfile: string): Promise<{ ok: boolean; invalid?: boolean }>` (`PUT`, `apiFetch`), `removeSteamAccount(): Promise<boolean>` (`DELETE`). Return typed result, never throw (mirror `auth-api.ts`/`game-request-api.ts`).
  - [ ] `frontend/src/features/auth/steam-account-section.tsx` (`"use client"`): input + Enregistrer / Modifier / Retirer, reads `useAuth().user?.steamProfile`, calls the api, then `setUser({ ...user, steamProfile })`. Place it in the account tabs next to the Discord control (`account-tabs.tsx` / wherever `DiscordButton` renders).
- [ ] **Tests**
  - [ ] Unit `SaveSteamAccountTest` (mock `UserRepositoryInterface`: saved / invalid_input / not_found; remove nulls the field).
  - [ ] Functional `SteamAccountEndpointTest` (login via `loginAs`, `PUT` valid ⇒ 200 + profile shows it, `PUT` invalid ⇒ 422, `DELETE` ⇒ 204 + profile null, unauthenticated ⇒ 401).
  - [ ] Frontend: extend the API-layer Jest suite with `steam-account-api.test.ts` if the layer is unit-tested (epic 20 story 20.7 established Jest for the API layer).

## Dev Notes

### Dependencies
- **Requires story 28.2** for `SteamProfileReference::parse` (the pure parser). If 28.2 is not yet merged, lift the parser first.
- Independent of 28.1 at the DB level, but conceptually part of the same epic.

### Auth gating decision
- Gate with **`requireAuthenticatedUser`** (any logged-in account), **not** member-only. Rationale: the Steam reference is a personal profile attribute and the coupling value applies to any logged-in user; `ROLE_MEMBER` is stale-prone and must not gate features (api/CLAUDE.md AC-M1). The epic's loose "member" wording refers to "logged-in", not membership. If true membership-gating is later required, switch to `ApiAccessGuard::requireAuthenticatedMember`.

### Reuse, don't reinvent
- Account-linked-external-account flow: `LinkDiscordToAccount` (Application) + `DiscordLinkController` (Presentation) + `User::linkDiscord/unlinkDiscord/getDiscordId` are the exact precedent - copy the structure (Steam is simpler: no OAuth, just store a validated string). [Source: api/src/Identity/Application/LinkDiscordToAccount.php, api/src/Identity/Presentation/DiscordLinkController.php, api/src/Identity/Domain/User.php:50-156]
- Profile payload extension: `ProfileController::profilePayload`. [Source: api/src/Identity/Presentation/ProfileController.php:40-52]
- Frontend auth user + fetch: `AuthUser` type and the `/account/profile` fetch in `auth-context.tsx`. [Source: frontend/src/features/auth/auth-context.tsx:21-69]
- Frontend authenticated calls: `apiFetch` + return-typed-or-null API functions. [Source: frontend/src/features/auth/auth-api.ts, frontend/src/features/games/game-request-api.ts]

### Architecture guardrails
- Domain `User` method stays pure (just sets the field). Command service = one unit of work, returns void/outcome, `$repo->save`. Controller: deserialize → validate → one Application call → `JsonResponse` (AC-P3/P4). Frontend: `process.env` only via `env.ts` (AC-ENV1); API base via `env.apiBaseUrl`; no `as` casts at the boundary - validate with type guards (AC-TS3). [Source: api/CLAUDE.md, frontend/AGENTS.md]

### Scope boundaries
- No vanity → SteamID64 resolution at save time (avoids coupling save to Steam availability); the coupling endpoint (28.2) handles resolution. Store the raw reference; name the column `steam_profile` to reflect it may be a vanity/URL, not only an id.
- The `/jeux` auto-couple that consumes `user.steamProfile` is story **28.4**; this story only saves/displays it on `/compte`.

### Project Structure Notes
- New (api): `Application/SaveSteamAccount.php`, `Presentation/SteamAccountController.php`, migration, `tests/Unit/Identity/SaveSteamAccountTest.php`, `tests/Functional/SteamAccountEndpointTest.php`.
- Modified (api): `Domain/User.php`, `Presentation/ProfileController.php`, possibly the anonymization path.
- New (frontend): `features/auth/steam-account-api.ts`, `features/auth/steam-account-section.tsx`.
- Modified (frontend): `features/auth/auth-context.tsx` (`AuthUser`), the account tabs, `confidentialite/page.tsx`.
- `services.yaml`: no change (Identity Application/Presentation autowired).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md] (story 28.3)
- Membership/auth rules: [Source: api/CLAUDE.md "Membership access control"]
- Discord precedent: [Source: api/src/Identity/Application/LinkDiscordToAccount.php], [Source: api/src/Identity/Presentation/DiscordLinkController.php]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-3-save-steam-account` (stacked on 28.2).
- Gated with `requireAuthenticatedUser` (any logged-in account), not member-only, per the documented decision.
- `steam_profile` cleared in `User::anonymizeForDeletion` (RGPD); `SaveSteamAccount` reuses `GameSelection\Domain\SteamProfileReference` (pure VO; cross-context Application→Domain import is allowed by the DDD validator - candidate to move to Shared\Domain later).
- `steamProfile` added to both the profile payload (`ProfileController`) and the login/me payload (`AuthController`) so `AuthUser` is consistent right after login (enables 28.4 pre-fill).
- All gates green: php-cs-fixer 0, phpstan 0, app:architecture:ddd exit 0, phpunit 1052/1052; frontend typecheck/lint/build clean.

### File List

**Added (api)**
- `api/migrations/Version20260615120001.php`
- `api/src/Identity/Application/SaveSteamAccount.php`
- `api/src/Identity/Presentation/SteamAccountController.php`
- `api/tests/Unit/Identity/SaveSteamAccountTest.php`
- `api/tests/Functional/SteamAccountEndpointTest.php`

**Modified (api)**
- `api/src/Identity/Domain/User.php` (steam_profile column, get/set, anonymize clears it)
- `api/src/Identity/Presentation/ProfileController.php` (steamProfile in payload)
- `api/src/Identity/Presentation/AuthController.php` (steamProfile in login/me payload)

**Added (frontend)**
- `frontend/src/features/auth/steam-account-api.ts`

**Modified (frontend)**
- `frontend/src/features/auth/account-profile.tsx` (Profile type + SteamSection)
- `frontend/src/features/auth/account-tabs.tsx` (render SteamSection)
- `frontend/src/features/auth/auth-context.tsx` (AuthUser.steamProfile)
- `frontend/src/app/(public)/confidentialite/page.tsx` (RGPD mention)
