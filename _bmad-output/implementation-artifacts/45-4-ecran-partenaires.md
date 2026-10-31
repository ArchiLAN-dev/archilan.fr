# Story 45.4 - L'écran partenaires : stream et LAN

**Status:** review
**Epic:** 45 - Mettre en avant nos partenaires (`_bmad-output/planning-artifacts/epics/epic-45-partenaires.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-45-story-4-ecran-partenaires` (partie de `feature/epic-45-story-3-partenaires-evenement` ;
PR à recibler sur `develop` quand 45.1 à 45.3 sont mergées)
**Dépend de:** 45.1 (`GET /api/v1/partners`), 45.3 (partenaires d'un événement, `joinEventPartners`)

## Story

En tant qu'organisateur,
je veux une page à afficher dans OBS et sur l'écran de la LAN,
afin que les viewers et les joueurs présents voient nos partenaires pendant l'event.

## Contexte (cartographie du 2026-10-30)

- Groupe `(overlay)` (`src/app/(overlay)/layout.tsx`) : sans shell, `noindex`. Route existante
  `/o/[sessionId]/[widget]` (deux segments) : `/o/partenaires` (un segment) ne la masque pas.
- Paramètres d'overlay : `src/features/overlay/overlay-params.ts` (`parseOverlayParams` : `pos`, `scale`,
  `duration`, `bg`), fond transparent posé sur `html`/`body` au montage (page widget).
- Panneau de liens OBS avec aperçu en damier : `src/features/overlay/overlay-links-panel.tsx`.
- Données : `GET /api/v1/partners` (visibles, ordre admin), `GET /api/v1/events/{id}` (`partners`, 45.3),
  `joinEventPartners()`.

## Décisions

1. Route `/o/partenaires`, page client (OBS et l'écran de LAN restent ouverts des heures) : liste relue toutes les
   5 minutes (`refetchInterval`), un partenaire ajouté ou masqué en admin suit sans toucher à OBS.
2. `?event=<id>` : les partenaires de l'événement, dans son ordre et avec sa mention ; sans paramètre, tous les
   partenaires affichés. Un événement introuvable ou privé n'affiche rien (pas de repli sur tous).
3. Mode **overlay** (défaut) : fond transparent (ou `bg` chroma key), un partenaire à la fois (pastille blanche du
   logo, nom et mention en blanc ombré), ancré par `pos` (défaut `bottom-right`), `scale`, rotation toutes les
   `duration` secondes (défaut 8).
4. Mode **écran** (`?mode=ecran`) : plein écran sur le fond du site, titre « Merci à nos partenaires », un partenaire
   à la fois en très grand (logo, nom, mention ou description), rotation lente (défaut 12 s), points de position.
5. Sans partenaire à montrer : overlay vide (rien à l'antenne) ; écran avec le seul titre.
6. Admin : bloc « Écran partenaires » (liens overlay et écran à copier, aperçu en damier pour l'overlay) sur la page
   partenaires d'un événement (avec `?event=`) et sur `/admin/partenaires` (tous les partenaires).

## Critères d'acceptation

1. `parsePartnerScreenParams()` : `mode`, `eventId`, paramètres d'overlay (défauts propres à l'écran) ;
   `partnerScreenUrl()` construit les liens ; tests.
2. `/o/partenaires` : modes overlay et écran, rotation, relecture périodique, filtre `?event=`.
3. Admin : bloc de liens avec copie et aperçu, sur les deux pages.
4. Tests front (paramètres, URL, rotation, rendu des deux modes, vide, bloc admin).
5. `pnpm gates` vert ; vérification réelle dans le navigateur (deux modes, avec et sans `event`).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Paramètres et URL.
- [x] **Task 2** (AC 2) - Page `/o/partenaires`.
- [x] **Task 3** (AC 3) - Bloc admin.
- [x] **Task 4** (AC 4, 5) - Tests, gates, vérification réelle.

## Dev Notes

- Pas de changement d'API.
- Gates front en local : `NEXT_PUBLIC_APP_URL=http://localhost:3000` (voir 45.1).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `partner-screen.tsx` : `parsePartnerScreenParams()` (mode, event, `pos` par défaut en bas à droite, 8 s en
  overlay et 12 s en écran sauf `duration`), `partnerScreenUrl()`, `rotationIndex()`, `PartnerScreen` (TanStack
  Query relu toutes les 5 minutes, une lecture ratée garde la liste à l'antenne), `PartnerOverlay`,
  `PartnerScreenWall`. Lecture dans `partner-screen-api.ts` (`fetchPartnerScreen`) et `fetchPartners()` côté
  navigateur dans `partners-api.ts`.
- Route `/o/partenaires` (groupe `overlay`, `noindex` hérité) avec son propre `QueryProvider` : le groupe n'a pas de
  shell.
- Admin : `PartnerScreenLinks` (liens à copier, « Ouvrir », aperçu de l'overlay sur damier, `CHECKER_STYLE` exporté
  depuis `overlay-links-panel.tsx`) sur `/admin/partenaires` et sur la page partenaires d'un événement.
- Gates : `pnpm gates` vert (1313 tests, build) avec `NEXT_PUBLIC_APP_URL=http://localhost:3000`. Pas de changement
  d'API.
- Vérification réelle (Chrome, 2 partenaires de test, ArchiLAN #3) : overlay en bas à droite, rotation, fond
  transparent, `noindex` ; écran filtré sur l'événement (un partenaire, sa mention, pas de points) ; blocs de liens
  et aperçu sur les deux pages admin. Données de test supprimées. Logos non affichés en local (limite Next 16 sur
  les IP locales, voir 45.2).

### File List

- `frontend/src/app/(overlay)/o/partenaires/page.tsx` (nouveau)
- `frontend/src/features/content/partner-screen.tsx` (nouveau)
- `frontend/src/features/content/partner-screen-api.ts` (nouveau)
- `frontend/src/features/content/partner-screen.test.tsx` (nouveau)
- `frontend/src/features/content/partners-api.ts` (modifié)
- `frontend/src/features/admin/partner-screen-links.tsx` (nouveau)
- `frontend/src/features/admin/admin-partners.tsx` (modifié)
- `frontend/src/features/admin/admin-event-partners.tsx` (modifié)
- `frontend/src/features/overlay/overlay-links-panel.tsx` (modifié)
