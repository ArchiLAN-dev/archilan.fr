# Story 14.10: Guided game creation form

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-05-11

---

## Story

As an admin,
I want a pre-filled creation form when I click Create from the catalogue,
So that manual input is minimised and data quality is higher from day one.

---

## Acceptance Criteria

1. Five sections: sheet data (pre-filled editable) / sheet links (read-only) / IGDB candidates / APWorld source / AP-specific fields (manual).
2. Sheet links clickable if `url` available; greyed with note "URL unavailable (Google API key not configured)" if `url: null`.
3. IGDB: up to 3 candidates loaded on open if `TWITCH_CLIENT_ID` configured; section hidden silently if unavailable or no results.
4. `apworld_source_url` pre-filled if a GitHub link detected in sheet links; static note "Version will be checked on next update run" - no synchronous GitHub call.
5. `coverImageAlt` generated client-side as `"{game name} - cover"` (using the IGDB candidate's `name`) when the admin selects a candidate; `coverImageCredit` left empty. The API does not provide this value.
6. Validation: name required; `apworld_source_url` must be `https://github.com/{owner}/{repo}` (or accepted variants per Story 14.7) if set - validated client-side before submit, enforced server-side on creation.
7. On successful creation: redirect to admin game detail page.

---

## File List

- `frontend/src/features/admin/admin-guided-game-creation.tsx` (new - guided form component, 5 sections)
- `frontend/src/app/(admin)/admin/jeux/nouveau/page.tsx` (modified - Suspense + useSearchParams routing to guided form)
- `frontend/src/features/admin/admin-catalogue-sync-page.tsx` (modified - "Créer" link now passes catalogue data as query params)

---

## Tasks

- [x] Create guided creation form component (5 sections)
- [x] Pre-fill sheet data fields (editable)
- [x] Render sheet links section (greyed with note when `url: null`)
- [x] Load IGDB candidates via `GET /api/v1/admin/catalog-sync/igdb-preview?name=...` on form open
- [x] Auto-detect GitHub URL from sheet links → pre-fill `apworld_source_url`
- [x] Set `coverImageAlt` on IGDB candidate selection
- [x] Validate `apworld_source_url` against Story 14.7 canonicalization rules (accepted variants, normalized form) before submit
- [x] On success: redirect to admin game detail page

---

## Change Log

- 2026-05-11: Story implemented - guided creation form with 5 sections, IGDB auto-load, GitHub URL detection, apworld_source_url validation, Suspense routing in nouveau/page.tsx. ESLint clean (1 `no-img-element` warning, same as igdb-game-search.tsx), TypeScript clean, 576/576 API tests passing.
- 2026-05-11: Fix - `igdbId` ajouté au type `Fields`, persisté dans `handleIgdbSelect`, inclus dans le payload POST (`igdb_id`). Validation `isValidApworldSourceUrl` renforcée : rejette `?` et `#` (alignement avec PHP `normalizeApworldSourceUrl`).

---

## Dev Agent Record

### Completion Notes

- **No backend changes needed**: `GET /api/v1/admin/catalog-sync/igdb-preview` (up to 3 candidates) and `POST /api/v1/admin/games` (with catalogue fields from 14.7) both already support the required shape.
- **Data flow**: catalogue page encodes `NewGame` as URL query params (`name`, `availability`, `adult`, `bundled`, `links`) on the "Créer" link → `nouveau/page.tsx` detects these via `useSearchParams` and renders `AdminGuidedGameCreation` instead of the basic form.
- **`AdminGuidedGameCreation`**: `CataloguePreset` prop, `useEffect` loads IGDB candidates on mount using a local `fetchCandidates` inside the effect (satisfies `react-hooks/set-state-in-effect`). Sections: (1) sheet data pre-filled editable, (2) sheet links read-only with greyed note when `url:null`, (3) IGDB candidates hidden when empty/unavailable, (4) APWorld source with GitHub auto-detect, (5) AP-specific fields (catalogSheetName, adultContent, bundledWithAp, availabilityLocked).
- **`coverImageAlt`** set to `"${candidate.name} - cover"` on IGDB select; `coverImageCredit` left empty (AC5).
- **`isValidApworldSourceUrl()`**: client-side mirror of PHP `normalizeApworldSourceUrl` logic - accepts base URL + `/releases`, `/releases/latest`, `/releases/tag/{tag}`, `/tree/{branch}` variants (AC6).
- **Redirect**: `window.location.href` on success - consistent with existing `BasicCreationForm` pattern.
- Note: UI not tested in browser (no browser access in this environment).
