# Epic 31 - Archipelago Install Tutorials

Status: planned (not started)
Date: 2026-06-19

## Goal

Help a player actually **install and set up** the Archipelago games they will play. Today the journey
(install Archipelago → get the game → install the apworld → configure the YAML → connect) is implicit
and game-specific, which blocks newcomers. This epic adds:

- a single, shared **generic "Installer Archipelago" guide** rendered on every game page (never
  re-authored), and
- **per-game, admin-editable install tutorials** (ordered, structured steps) surfaced on the public
  game detail page `/jeux/[slug]`, later in the registration/run flow.

## Decisions (locked)

- **Reuse model = option 2:** one **global generic guide** shared across all games + **per-game steps**.
  No platform/client reusable-guide library in this epic (option 3 deferred until real duplication shows).
- **Content model = structured, persisted, admin-editable from the start.** A game's tutorial is an
  **ordered list of steps**, each `{type, title, description (light markdown), links: [{label, url}]}`.
  Stored as a JSON column on the `Game` aggregate (mirrors `option_types` / `platforms`).
- **Step types (enum):** `acquire` (se procurer le jeu) · `apworld` (installer l'apworld) · `client`
  (installer le client/patcher) · `yaml` (configurer le YAML) · `connect` (se connecter) · `note`.
- **Sheet links = folded into steps, seed-once then admin-owned (option B).** The Google-Sheet
  "Links & Downloads" are no longer shown as a separate live section; they **seed** a game's tutorial
  once, after which the persisted steps are the source of truth (admin edits/reorders them). The live
  Sheet resolution + the separate "Liens & ressources" block are removed from the public detail read.
- **Auto-seed (never a blank page):** a game with no authored tutorial is seeded from existing data -
  `bundledWithAp` → "Rien à installer, inclus dans Archipelago"; else `apworldSourceUrl` → "Installer
  l'apworld"; the Sheet links → folded into a resources/apworld step. The admin starts from a pre-filled
  draft and refines it.
- **Dependency direction:** per-game steps live in **GameSelection** (attached to `Game`). Sheet-link
  seeding needs CatalogSync data, so GameSelection defines a `GameCatalogLinksProviderInterface`
  (Application) implemented by a CatalogSync adapter (CatalogSync already depends on GameSelection -
  the direction holds; never the reverse).

## MVP & cross-cutting constraints

- **MVP cut line:** **31.1 + 31.2 + 31.8** ("tutos corrects et visibles" - per-game steps authored,
  rendered publicly, with version-match guidance). Follow-on: 31.3 (generic guide), 31.4 (flow nudge),
  31.6/31.7 (community), 31.5 (checklist/media, later).
- **Render safety (security):** step `description` is treated as **plain text by default** (the project
  renders rich content via `dangerouslySetInnerHTML` and ships **no sanitizer**, so raw HTML is unsafe -
  especially once 31.6 lets users submit content). If markdown is wanted, it must go through a
  **sanitizing** renderer (new dependency) restricted to a safe subset. **Community-submitted content is
  never rendered as raw HTML.**
- **Link validation:** every step `links[].url` must be validated to **http/https only** (reject
  `javascript:` etc.) and rendered with `rel="noopener noreferrer"`. Enforced in the shared
  `InstallStepsNormalizer` (built in 31.1) so admin authoring (31.1) and community submission (31.6)
  share one rule.
- **Shared building blocks live in 31.1:** the `InstallStepsNormalizer` (validation/normalization) and a
  reusable `InstallStepsEditor` component are created in 31.1 and **consumed** by 31.6/31.7 - not
  extracted/refactored later.
- **31.2 sequencing gate:** before 31.2 removes the live Sheet "Liens & ressources" section, it must
  (a) run the 31.1 bulk seed and (b) expose `installSteps` on `GET /api/v1/games/{slug}` - otherwise
  public pages regress (lose the Sheet links).

## Scope

### In scope
- Persisted per-game install steps on `Game` + admin authoring (ordered editor: type, title, description,
  links; add/reorder/delete) in `/admin/jeux/[gameId]`.
- Auto-seed from `bundledWithAp` / `apworldSourceUrl` / Sheet links.
- Public render on `/jeux/[slug]` (per-game steps + the shared generic guide), replacing the live
  "Liens & ressources" Sheet section.
- Admin-editable **generic "Installer Archipelago"** guide (single global record) + a standalone
  `/aide/archipelago` page.
- Contextual reminder in the registration/run flow ("voici comment installer tes jeux sélectionnés").
- **Community contributions** (any authenticated user): submit a structured tutorial change on an
  existing game, or propose docs for a game **not yet in the catalog**; **admin moderation queue** to
  review, apply, or reject contributions.

### Out of scope
- Platform/client reusable-guide library (option 3).
- **Auto-creating a not-yet-listed game** from an approved contribution (the moderator uses it to seed a
  manual game creation; full automation is later).
- Versioning / full revision history of tutorials and public edit-suggestion diffs beyond the
  pending → approved/rejected moderation flow.
- Interactive checklist with persisted per-user progress.
- Screenshot uploads (MinIO) and embedded videos in steps (text/markdown + links only for now).
- Auto-propagation of later Google-Sheet changes into already-authored tutorials (seed is one-shot).

## Affected systems (anticipated)

- **api/ `GameSelection`** - `Game` gains an `install_steps` JSON column (+ get/set + a step value object
  / validation), admin endpoints to save the ordered steps and to seed a draft, and the detail payload
  exposes the steps. `GameCatalogLinksProviderInterface` (Application) for seeding.
- **api/ `CatalogSync`** - adapter implementing `GameCatalogLinksProviderInterface` (wraps
  `CatalogSyncService::findEntryForNames`); the public detail read drops the live Sheet merge once steps
  are the source of truth (story 31.2).
- **api/ `Content`** (or a simple global record) - the generic guide content (story 31.3).
- **frontend/** - admin editor section in `admin-game-editor.tsx`; render block in `game-detail.tsx`
  (replacing the "Liens & ressources" section); `/aide/archipelago` page; registration/run flow nudge.

## Proposed stories

- **31.1 - Per-game install steps: model + admin authoring + auto-seed + bulk seed (api/ + frontend).**
  `install_steps` JSON on `Game` (+ step validation, types enum), migration; admin save endpoint
  (`PATCH /api/v1/admin/games/{gameId}/tutorial`) and seed endpoint
  (`POST /api/v1/admin/games/{gameId}/tutorial/seed`) composing a draft from `bundledWithAp` /
  `apworldSourceUrl` / Sheet links (via `GameCatalogLinksProviderInterface`); a **bulk-seed command**
  `app:games:seed-tutorials` (cold start: seed every game with no steps, idempotent, `--force`); detail
  payload exposes `installSteps`; admin editor "Tutoriel d'installation" section (ordered: type, title,
  description, links; add/↑↓/delete + "Générer un brouillon"). No public render yet. Tests + gates.
- **31.2 - Public render on the game detail page (frontend + small api/).** Render the per-game steps on
  `/jeux/[slug]` (descriptions as **plain text / sanitized**, links validated per the cross-cutting
  constraints); expose `installSteps` on the public `GET /api/v1/games/{slug}` payload; **remove** the
  separate live "Liens & ressources" Sheet section (links now live inside steps) **only after** the
  sequencing gate above is met. Empty-state handled (auto-seeded draft means there is normally content).
- **31.3 - Generic "Installer Archipelago" guide (api/ + frontend).** A single admin-editable global guide
  (steps or markdown) rendered atop every game's tutorial + a standalone `/aide/archipelago` page linked
  from the nav/footer.
- **31.4 - Install nudge in the registration / run flow (frontend + small api/).** After game selection,
  surface "voici comment installer tes jeux sélectionnés" linking to each game's tutorial.
- **31.5 (later) - Interactive checklist + media.** Per-user cochable progress, screenshots (MinIO),
  embedded videos.
- **31.6 - Community contributions: public submission (api/ + frontend).** Any authenticated user can
  submit a `GameTutorialContribution` - **structured steps** (same model as 31.1) + optional
  message-to-moderator - either on an **existing game** (`gameSlug`) or for a **not-yet-listed game**
  (`proposedGameName`, picked from the existing `catalog-games` list). Domain entity + repo, submit
  command (reuses the 31.1 step normalization), "mes contributions" query, public endpoints. UI: a
  "Proposer une modification" affordance on `/jeux/[slug]` and a "Proposer une doc (jeu non listé)" entry
  near the existing game-request section. Status starts `pending`. No moderation yet.
- **31.7 - Community contributions: admin moderation & apply (api/ + frontend).** Admin moderation queue
  (new tab in `/admin/moderation`): list pending contributions, view the proposed steps (diff vs the
  game's current `install_steps` for existing games), **approve** (existing game → apply steps to
  `install_steps`; not-yet-listed → mark approved, content available to seed a manual game creation) or
  **reject with a reason**. Author notified post-commit (Notifier, per Epic 30). Approve/reject endpoints
  + status transitions on the aggregate.
- **31.8 - Version-match guidance (api/ + frontend).** Make the tutorial *correct*: pin the **deployed
  apworld version** + release link in the apworld step (reuses existing per-game data), add a single
  admin-editable **Archipelago client** version + launcher download surfaced in the generic guide, and a
  reusable "your versions must match the session" callout. Prevents the #1 multiworld failure
  (version mismatch). Per-session version override deferred.

## Sequencing

`31.1` (model + admin authoring + seed) → `31.2` (public render, drop the live Sheet section) →
`31.3` (generic guide) → `31.4` (flow nudge). Community track builds on 31.1's step model:
`31.6` (public submission) → `31.7` (admin moderation & apply). `31.5` (checklist/media) later.
`31.8` (version-match) renders inside the apworld step (31.1/31.2) + generic guide (31.3), sequence
after them; the global client-info record can be built independently.
A reusable `InstallStepsEditor` component extracted in 31.1/31.2 is reused by 31.6.

## Risks / notes

- **Seed cross-context:** GameSelection must not depend on CatalogSync. Use the
  `GameCatalogLinksProviderInterface` (defined in GameSelection, implemented in CatalogSync) for the
  seed; keep the write (persisting steps) inside GameSelection.
- **Migration off the live Sheet:** once 31.2 ships, the public detail page no longer resolves the Sheet
  live - the `PublicGameDetailQuery` Sheet merge built in story 28.9 becomes seeding-only. Plan the
  transition so existing games get seeded before the live section is removed.
- **PATCH-array semantics:** the admin saves the whole ordered steps array (like the YAML options),
  reordering = reordering the array; validate types/trim server-side.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-19 | Epic planned from discussion. Reuse = option 2 (global generic guide + per-game steps); structured admin-editable steps persisted on `Game`; Sheet links folded into steps, seed-once then admin-owned (B) with auto-seed. Stories 31.1-31.5 proposed. |
| 2026-06-19 | Community track added: 31.6 (public submission of structured contributions on an existing game or a not-yet-listed game, any authenticated user) + 31.7 (admin moderation queue, apply/reject). |
| 2026-06-19 | Added bulk-seed (`app:games:seed-tutorials`) to 31.1 for cold start, and Story 31.8 (version-match guidance: pin deployed apworld version + Archipelago client version) - the primordial correctness piece. |
| 2026-06-19 | Spec review applied: render-safety (no raw HTML for step descriptions; sanitize or plain text) + link-URL validation as cross-cutting constraints; shared `InstallStepsNormalizer` + `InstallStepsEditor` created in 31.1 (consumed by 31.6/31.7); MVP cut line (31.1+31.2+31.8); 31.2 sequencing gate; pinned 31.8 client-info storage; 31.7 explicit replace-whole + diff. |