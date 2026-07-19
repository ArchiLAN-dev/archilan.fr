# Story 34.6: Editorial & keyword content pass (frontend/ content)

Status: done

## Story

As a francophone player searching Google for "Archipelago multiworld", "randomizer coopératif" or a local
LAN, and as the ArchiLAN association wanting those searches to land on the right page,
I want the indexable pages' H1s, intro copy, internal links and alt-texts rewritten against a validated
keyword map,
so that each page clearly targets one search intent, in natural French, without keyword stuffing.

## Context

Epic 34 gap: metadata (34.2) and structured data (34.3) are in place, but the visible **copy** is
brand-voiced, not search-legible. Current H1s (verified 2026-07-15):

| Page | Current H1 | Current intro (first sentence) |
|---|---|---|
| `/` | Joue pour toi, gagne pour tous. | ArchiLAN organise des événements autour d'Archipelago... |
| `/evenements` | Rejoins une session Archipelago. | Parcours les prochaines sessions ouvertes... |
| `/actualites` | Suivre la communauté entre deux sessions. | Annonces, récaps d'événements et nouvelles de l'association... |
| `/jeux` | Les jeux de la communauté. | Tous les jeux supportés dans nos événements Archipelago... |
| `/runs-hebdo` | Runs hebdomadaires | Choisis un jeu pour voir le run de la semaine... |
| `/aide/archipelago` | Installer Archipelago | Les étapes pour installer le client Archipelago... |

They read well but carry almost none of the target keywords (Archipelago **multiworld**, **randomizer**,
**LAN en France**, **coopératif**). This story rewrites them against the keyword map below - **but only
after the product owner validates the map** (locked decision: "keyword map validated by the product owner
before copy changes"). Copy stays natural French addressed to humans first (locked: no keyword stuffing).

## Keyword map (AC1 - the worklist, PENDING VALIDATION)

Two clusters, each term mapped to exactly one target page.

### Cluster A - Archipelago / randomizer (francophone)

| Target page | Primary keyword | Secondary keywords |
|---|---|---|
| `/aide/archipelago` | comment jouer à Archipelago | installer Archipelago, client Archipelago, première partie multiworld, guide randomizer |
| `/jeux` | jeux compatibles Archipelago | catalogue randomizer, jeux multiworld, jeux supportés Archipelago |
| `/jeux/[slug]` | {jeu} Archipelago | {jeu} randomizer, {jeu} multiworld, seed {jeu} |
| `/runs-hebdo` | runs Archipelago hebdomadaires | seed de la semaine, randomizer hebdomadaire, classement multiworld |
| `/` (home) | Archipelago multiworld en France | randomizer coopératif, communauté Archipelago francophone |

### Cluster B - LAN local / événementiel

| Target page | Primary keyword | Secondary keywords |
|---|---|---|
| `/evenements` | LAN Archipelago en France | événement multiworld, soirée jeux vidéo coopératif, s'inscrire LAN |
| `/actualites` | actualités Archipelago ArchiLAN | récaps LAN, annonces communauté randomizer |

### Proposed H1 / intro rewrites (natural French, keyword-front-loaded)

| Page | Proposed H1 | Intro tweak |
|---|---|---|
| `/` | *(keep brand H1 "Joue pour toi, gagne pour tous.")* | strengthen the lead paragraph to name "multiworld randomizer" and "en France" naturally (the eyebrow already says "Association Archipelago en France") |
| `/evenements` | Événements et LAN Archipelago en France | "Nos LAN et événements multiworld : parcours les prochaines sessions..." |
| `/actualites` | Actualités de la communauté Archipelago | keep, add "randomizer" once naturally |
| `/jeux` | Jeux compatibles Archipelago | keep (already strong: "jeux supportés... Archipelago") |
| `/runs-hebdo` | Runs Archipelago hebdomadaires | "Une nouvelle seed Archipelago chaque semaine..." |
| `/aide/archipelago` | Installer Archipelago et jouer en multiworld | keep |
| `/jeux/[slug]` | *(keep `{game.name}` as H1)* | add a keyworded subtitle/lead: "{jeu} sur Archipelago - randomizer multiworld" in the game detail template |

> Home and game-detail keep their existing H1 (brand line / game name) and get the keyword into the **intro
> lead** instead, to preserve identity. Every other listing H1 front-loads its primary keyword. This
> brand-vs-SEO balance is exactly what the product owner validates.

## Acceptance Criteria

1. **AC1 - Keyword map validated.** The map above is reviewed and approved (or adjusted) by the product
   owner before any copy change. Committed as this story's worklist.
2. **AC2 - H1s & intros.** Home, the listing pages and `/aide/archipelago` H1s/intro copy rewritten against
   the validated map; exactly one `<h1>` per page; heading hierarchy (h1 -> h2 -> h3) validated (no skips).
3. **AC3 - Internal linking.** The tutorial hub (`/aide/archipelago`) links to game pages and events;
   event/post pages link back to the relevant hubs; footer links audited.
4. **AC4 - Alt text.** Meaningful images on indexable pages get descriptive French alt text; decorative
   images stay `alt=""`.
5. **AC5 - No stuffing; gates.** Copy reads naturally (no keyword stuffing); `pnpm gates` green.

## Tasks / Subtasks

- [x] Task 1: validate the keyword map (AC: 1) - **BLOCKING; product-owner sign-off before any copy edit.**
- [x] Task 2: H1s & intros (AC: 2) - apply the validated rewrites to the 6 pages + the game-detail lead;
      verify one h1 per page and no heading-level skips.
- [x] Task 3: internal linking (AC: 3) - tutorial hub -> games/events; event/post -> hubs; footer audit.
- [x] Task 4: alt-text pass (AC: 4) - audit `next/image`/`img` on indexable pages; decorative stays `alt=""`.
- [x] Task 5: verify + ship (AC: 5) - `pnpm gates`; dev smoke of the rewritten pages; PR to `develop`.

## Dev Notes

- **This is a content story** - no new components, no logic. Edits are H1/paragraph strings, `<Link>`s and
  `alt=` values in existing pages/components.
- Decorative images already use `alt=""` + `aria-hidden` in several places (event hero, gallery, home hero);
  the alt pass is mostly *verifying* those and adding real alt where an image is informative.
- Heading hierarchy: several pages use an eyebrow `<p>` then `<h2>` for sections - keep the single `<h1>` at
  the top; section headings stay `<h2>`, sub-cards `<h3>`. Do not introduce a second `<h1>`.
- Metadata titles were set in 34.2 (keyword skeleton); if the validated H1 copy shifts wording, the page
  `<title>`/description may be nudged to match - but keep titles <= ~60 chars and unique (34.2 rule).
- No em-dashes (root CLAUDE.md). AC-ENV1 etc. still apply (content-only, low risk).

### References

- Epic: `_bmad-output/planning-artifacts/epics/epic-34-seo-search-visibility.md` (story 34.6, "keyword map
  validated by product owner" + "no keyword stuffing" locked decisions).
- Predecessors: 34.2 (metadata skeleton), 34.3 (structured data), 34.5 (headings render real fonts now).
- Standards: `frontend/AGENTS.md`; root `CLAUDE.md`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- **AC1**: keyword map validated by the product owner (2026-07-16, "valide tout, applique") - the
  "Valide tout" H1 approach: front-load the primary keyword on the listing H1s, keep the brand H1 on home
  and the game name on `/jeux/[slug]` (keyword goes into the intro/subtitle there).
- **AC2 (H1s + intros)**: applied to `/evenements`, `/actualites`, `/jeux`, `/runs-hebdo`,
  `/aide/archipelago` (new H1 + keyword-aware intro), home (intro lead now "Archipelago multiworld en
  France : un randomizer coopératif..."), and the game-detail template (keyworded subtitle
  "{jeu} sur Archipelago - randomizer multiworld"). Dev smoke confirmed exactly one `<h1>` per page.
- **AC3 (internal linking)**: `/aide/archipelago` gained a "Et ensuite ?" section linking to `/jeux` and
  `/evenements`; event detail gained a "Tous les événements" back-link (`/evenements`); post detail gained
  a "Toutes les actualités" back-link (`/actualites`). Footer audited: already links the tutorial hub +
  actualités + legal; the header nav covers events/runs/jeux/communauté - adequate, no change.
- **AC4 (alt text)**: audit found decorative images already correct (`alt="" aria-hidden` on card
  thumbnails, footer logo) and meaningful images already good (game covers `alt={coverImageAlt||name}`,
  home hero descriptive). Improved the two content **hero** images (event + post detail): descriptive alt
  ("Événement Archipelago ArchiLAN : {title}" / "Illustration de l'article : {title}"), dropped the now
  redundant `aria-hidden` + section `aria-label`.
- **AC5**: copy reads naturally (no stuffing); `pnpm gates` green (typecheck 0, lint 0 errors, jest
  194/194, build clean).

### File List

- `frontend/src/app/(public)/page.tsx` (home intro lead)
- `frontend/src/app/(public)/evenements/page.tsx`, `.../actualites/page.tsx`, `.../jeux/page.tsx`,
  `.../runs-hebdo/page.tsx`, `.../aide/archipelago/page.tsx` (H1 + intro; aide also gets outbound links)
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx` (hub back-link + hero alt)
- `frontend/src/app/(public)/actualites/[postSlug]/page.tsx` (Link import + hub back-link + hero alt)
- `frontend/src/features/games/game-detail.tsx` (keyworded subtitle)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-16 | Story created from epic 34 (story 34.6). Keyword map drafted (2 clusters, one term per page) + proposed H1/intro rewrites, grounded in the current brand-voiced copy. Status: awaiting product-owner validation of the map before any copy change (AC1 gate). |
| 2026-07-16 | Keyword map validated by product owner. Applied: H1s + intros (6 pages + game subtitle), internal linking (tutorial hub outbound, event/post back-links, footer audit), alt-text pass (content hero images). `pnpm gates` green, one h1 per page. Status: done. |
