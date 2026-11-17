# Story 54.7 - Le tracker depuis mes parties

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-7-tracker-depuis-mes-parties`
**Dépend de:** 54.6.

## Story

En tant que joueur inscrit à une partie,
je veux ouvrir le tracker de mon slot en un clic, déjà connecté,
afin de ne pas recopier adresse, nom de slot et mot de passe.

## Contexte

- Infos de connexion d'un slot : `ConnectionData` / `parseSession` (`features/events/events-api.ts` : `host`, `port`,
  `connectionUri`), slots et mots de passe, affichés par `ConnectionFields` (`components/connection-fields.tsx`).
- Écrans qui montrent ces infos : partie d'event (`features/events/session-connection-gate.tsx`, page
  `/evenements/[slug]/inscription/[id]/session`), partie hebdo (`features/weekly-runs/weekly-run-slot-page.tsx`),
  partie privée (pages `/runs/[runId]/...`). À recenser au début de la story (grep de `ConnectionFields`).
- Un slot porte son jeu. Les packs d'un jeu se lisent par `GET /api/v1/games/{gameId}/tracker-packs` (54.2).
- SSR anonyme (cookie `__Host-`, story 32.5) : tout ce qui touche au slot se lit côté client avec `apiFetch`.

## Décisions

1. **Bouton « Ouvrir le tracker »** à côté de `ConnectionFields`, sur chaque slot dont le jeu a au moins un pack actif.
   Il ouvre un nouvel onglet : le joueur garde le tracker à côté de son jeu.
2. **Pas de secret dans l'URL** : le bouton ouvre `/jeux/[slug]/tracker?slot={slotRef}`, où `slotRef` identifie le
   slot dans son contexte (`event:{registrationId}:{slotId}`, `weekly:{runId}:{slotId}`, `run:{runId}:{slotId}`). La
   page du tracker relit les infos de connexion par les endpoints existants, avec la session du joueur, puis se
   connecte. Pas de nouvel endpoint si les existants suffisent. Sinon, un endpoint de lecture
   `GET /api/v1/me/slots/{slotRef}/connection`, réservé au propriétaire du slot.
3. **Connexion automatique** si la session est `running` et que `connectionUri` est présent. Sinon, le tracker s'ouvre
   en manuel avec un bandeau « la partie n'est pas lancée, le tracker se connectera quand elle le sera » (relecture
   périodique, ou temps réel si le flux SSE de la session est déjà disponible).
4. **Variante** : la variante par défaut du pack, modifiable, et gardée par slot dans `localStorage`.
5. **Clé d'état local** : celle de 54.5 suffixée par `slotRef`. Deux slots n'écrasent pas leurs états.
6. **Pas de tracker pour les slots d'un autre joueur** dans cette story (spectateur : hors périmètre, voir l'epic).

## Critères d'acceptation

1. Sur la page de ma partie d'event, mon slot Majora's Mask montre « Ouvrir le tracker ». Le tracker s'ouvre et se
   connecte sans rien saisir.
2. Pareil pour une partie hebdo et une partie privée.
3. Un jeu sans pack actif n'a pas de bouton.
4. Aucun mot de passe n'apparaît dans l'URL, l'historique ou les logs.
5. Une partie pas encore lancée ouvre le tracker en manuel, et il se connecte au lancement.
6. Un joueur qui n'est pas propriétaire du slot ne peut pas obtenir ses infos de connexion par la page du tracker.

## Tests et gates

7. Composants : bouton (avec ou sans pack), construction de `slotRef`.
8. Page du tracker : résolution de `slotRef` vers la connexion (API simulée), partie non lancée puis lancée, refus.
9. Fonctionnels API seulement si un endpoint est ajouté (propriétaire, autre joueur 403, visiteur 401).
10. `pnpm gates` (et `composer gates` si l'API change) verts.
11. Vérification réelle sur une partie d'event locale.

## Tasks / Subtasks

- [x] **Task 1** - Recenser les écrans qui affichent `ConnectionFields` et les endpoints de connexion.
- [x] **Task 2** (AC 1, 2, 3) - Bouton, packs du jeu du slot.
- [x] **Task 3** (AC 1, 4, 6) - `slotRef`, résolution côté tracker, éventuel endpoint.
- [x] **Task 4** (AC 5) - Partie pas encore lancée, connexion au lancement.
- [ ] **Task 5** (AC 10, 11) - Gates et vérification réelle.
  - [x] Gates.
  - [ ] Vérification réelle sur une partie d'event locale : à faire (voir « Vérifications »).

## Dev Agent Record

### Écrans recensés (Task 1)

`ConnectionFields` est affiché par 6 écrans, tous branchés :

| Écran | Contexte du slot | Lecture de la connexion |
|---|---|---|
| `events/session-connection-gate.tsx` (page session d'un inscrit) | `event` | `GET /registrations/{id}/session-connection` |
| `registrant-hub/hub-tools.tsx` (carte « Ta partie » de Mon event) | `event` | idem |
| `personal-runs/personal-run-detail-page.tsx` (2 blocs) | `run` | `GET /runs/{id}` (`mySlots`) |
| `weekly-runs/weekly-run-card.tsx`, `weekly-run-game-client.tsx`, `weekly-run-slot-page.tsx` | `weekly` | `GET /weekly-runs/current` (`myEntry`) |

### Fichiers

- API (lecture seule, aucun endpoint ajouté) :
  - `Sessions/Application/Query/PlayerSessionConnection.php` : chaque slot porte `gameSlug` ;
  - `PersonalRuns` (`MyRunSlotsQueryInterface`, `DbalMyRunSlotsQuery`, `PersonalRunDrafts`) : `mySlots` porte `gameId` et `gameSlug` (null pour une seed importée) ;
  - `WeeklyRuns/Infrastructure/Dbal/DbalCurrentWeeklyRunsQuery.php` : chaque run porte `gameId` et `gameSlug` ;
  - tests fonctionnels étendus : `PlayerSessionConnectionTest`, `PersonalRunMySlotsTest`, `CurrentWeeklyRunsTest`.
- Front :
  - `features/tracker/slot-ref.ts` : `formatSlotRef`, `parseSlotRef`, `trackerSlotHref`, `slotTracker` ;
  - `features/tracker/tracker-slot-api.ts` : `fetchSlotConnection(ref)` compose les fetchers existants (`ready`, `waiting`, `denied`, `error`) ;
  - `features/tracker/tracker-slot-link.tsx` : le bouton, affiché seulement si le jeu a un pack (`fetchGameTrackerPacks`, TanStack, 5 min) ;
  - `components/slot-name-field.tsx` (`ConnectionSlot.tracker`), `components/connection-fields.tsx` (bouton à côté du nom de slot) ;
  - `personal-runs/run-connection-slots.ts`, `weekly-runs/weekly-connection-slots.ts` : les slots de chaque contexte avec leur tracker ;
  - `features/tracker/ui/slot-connection.tsx` : `useSlotConnection` (relecture toutes les 15 s tant que la partie n'est pas lancée, toutes les 60 s une fois lancée), `useAutoConnect`, `SlotBanner` ;
  - `tracker-screen.tsx`, `use-tracker-session.ts`, `tracker-storage.ts` : `?slot=`, état et choix de variante par slot ;
  - page `jeux/[slug]/tracker` : lit `?slot=`.

### Décisions prises en cours de route

- **Le slot est désigné par son nom, pas par un id** : `event:{registrationId}:{nom}`, `run:{runId}:{nom}`, `weekly:{weeklyRunId}`. Le nom est unique dans un multiworld (Archipelago refuse deux slots du même nom), il est déjà renvoyé par les trois endpoints, et il n'a rien de secret. Un id de slot aurait demandé de l'ajouter à `mySlots` et au hebdo pour rien. Une partie hebdo n'a qu'un slot par joueur.
- **Pas de nouvel endpoint** : les trois endpoints existants ne répondent qu'à qui joue le slot (inscription du joueur, participant de la partie privée, entrée hebdo du joueur). Un slot d'un autre donne `denied` et le tracker reste en manuel (AC 6). L'API n'a gagné que le jeu des slots (id et slug), nécessaire au bouton.
- **Le bouton est dans `ConnectionFields`** : un seul endroit pour les 6 écrans. Un slot sans jeu du catalogue (seed importée) n'a pas de bouton.
- **Connexion automatique une fois par adresse et slot** : un joueur qui se déconnecte reste déconnecté. Une relance sur un autre port (nouvelle adresse) reconnecte.
- **Jeu vérifié** : un slot d'un autre jeu que celui de la page n'est pas connecté.
- **Variante par slot** : `tracker:choice:{slotRef}` ; l'adresse (`?variant=`) l'emporte.
- **État par slot** : `tracker:{pack}:{version}:{variante}:{slotRef}`.

### Vérifications

- **Gates** : `composer gates:fast` vert (3998 tests). Front : typecheck, lint et tests verts (1847 tests, dont 6 sur les références de slot, 4 sur la résolution par contexte, 1 sur le bouton, 5 sur l'écran ouvert sur un slot). `next build` compile seul ; lancé par `pnpm gates`, il s'arrête sur `SecurityFatalError` (faille de la version de Next installée, corrigée par le hotfix #1105/#1106 pas encore mergé).
- **Vérification réelle : pas faite.** Elle demande une partie d'event lancée en local (orchestrateur, room Archipelago) sur un jeu qui a un pack. L'essai réel de 54.6 (room MM) n'a pas pu être fait non plus ; les deux se font ensemble.

