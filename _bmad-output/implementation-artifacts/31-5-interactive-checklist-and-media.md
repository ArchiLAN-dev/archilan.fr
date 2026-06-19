# Story 31.5: Interactive checklist + step media

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player following a game's install tutorial,
I want to tick off the steps as I go and see screenshots/videos for the tricky ones,
so that I can track my progress and follow visual guidance.

Fifth story of Epic 31 (the "later" polish bundle - now all three parts).

## Acceptance Criteria

1. **Interactive checklist.** On `/jeux/[slug]` (and `/aide/archipelago`), each step shows a checkbox; the
   completed set is persisted in **localStorage** (per `storageKey`), so progress survives reloads with
   **no account needed**. Done steps are visually struck through. No backend.
2. **Step images.** A step can carry an `imageUrl`; it renders as an image. Authored via the step editor
   (URL field), validated http(s) by the shared `InstallStepsNormalizer` (https assumed for a bare URL;
   unsafe schemes dropped). Exposed on the public payload.
3. **Step videos.** A step can carry a `videoUrl`; **YouTube** URLs embed in a sandboxed `youtube-nocookie`
   iframe, any other URL renders as a plain link. URL validated like images.
4. **Shared everywhere.** Images/videos flow through the same step model used by per-game tutorials, the
   generic guide, and community contributions (no per-surface special-casing). The moderation diff renders
   them read-only (no checkboxes there).
5. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`) and
   frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [ ] **api/**: `InstallStepsNormalizer` emits `imageUrl`/`videoUrl` (optional, http(s) sanitized);
      `DbalGameCatalogQuery::decodeInstallSteps` carries them onto the public payload. No migration
      (JSON columns are additive); the guide passes them through (raw steps).
- [ ] **frontend**: `GameStep`/`InstallStep` gain optional `imageUrl`/`videoUrl` + guard; editor gets
      image/video URL fields; `InstallStepsView` becomes a client component rendering image + safe video
      embed + an optional **localStorage checklist** (`storageKey`), wired on the game detail and guide
      (not on the moderation diff).
- [ ] Tests: normalizer media sanitization, public payload exposes media.

## Dev Notes

- **Scope decisions**: step images are **URL-based** (consistent with the game cover field), not a
  per-step MinIO upload widget - the epic's "(MinIO)" is satisfied by referencing a hosted URL; an upload
  widget is deferred. Videos embed **YouTube only** (sandboxed `youtube-nocookie`); other providers fall
  back to a link to avoid arbitrary-iframe injection.
- **Checklist** is localStorage-only (chosen): zero backend, works for anonymous visitors; keyed
  `archilan.install-progress.{storageKey}` (`game-{slug}` / `archipelago-guide`).
- **Safety**: descriptions stay plain text; image/video/link URLs are http(s)-validated server-side; the
  video iframe is sandboxed + nocookie. No `dangerouslySetInnerHTML`.
- **Reuse**: single step model + `InstallStepsView` shared by detail / guide / moderation diff.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-31-archipelago-install-tutorials.md]
- Prior: [Source: _bmad-output/implementation-artifacts/31-1-install-steps-model-and-admin-authoring.md], [Source: _bmad-output/implementation-artifacts/31-2-public-render-game-detail.md]
- Standards: [Source: api/CLAUDE.md], [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Implemented on branch `feature/epic-31-story-5-checklist-media` (from develop).
- Normalizer emits sanitized `imageUrl`/`videoUrl`; public detail decoder carries them; guide passes raw.
- `InstallStepsView` is now a client component: image render, YouTube-only sandboxed embed (else link),
  and a localStorage checklist when `storageKey` is set (game detail + guide). Moderation diff stays plain.
- Step editor gained image/video URL fields. No migration (additive JSON).
- Gates green: php-cs-fixer 0, phpstan 0 (src+tests), DDD exit 0, phpunit (step-shape suites 43 + 2 media tests); FE typecheck/lint/build, jest 55.

### File List

**Modified (api)**
- `api/src/GameSelection/Application/InstallStepsNormalizer.php` (imageUrl/videoUrl)
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (decode media)
- `api/tests/Unit/GameSelection/InstallStepsNormalizerTest.php`, `api/tests/Functional/PublicGameDetailTest.php`

**Modified (frontend)**
- `frontend/src/features/games/public-games-api.ts` (GameStep media + guard)
- `frontend/src/features/games/install-steps-editor.tsx` (image/video fields)
- `frontend/src/features/games/install-steps-view.tsx` (client: image/video/checklist)
- `frontend/src/features/games/game-detail.tsx` + `frontend/src/app/(public)/aide/archipelago/page.tsx` (storageKey)