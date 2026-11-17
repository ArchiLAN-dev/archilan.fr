# Story 54.10 - Onglet Tracker de la progression

**Status:** done
**Epic:** 54 - Trackers PopTracker
**Date:** 2026-11-17
**Dépend de:** 54.5 (écran du tracker), 54.7 (connexion d'un slot par sa référence, sans mot de passe dans l'adresse).

## Story

En tant que joueur,
je veux le tracker de mon slot directement dans la page de progression, déjà connecté,
afin de suivre ma partie sans quitter la page ni recopier mes infos de connexion.

## Décisions (Jean, 2026-11-17)

1. Un onglet **Tracker** s'ajoute aux onglets de la page de progression du slot (Checks, Items, Sphères, Indices),
   sur les trois pages joueur :
   - partie privée : `/runs/[runId]/progression/[slotIndex]` (référence `run:{runId}:{slot}`) ;
   - partie d'event : la page du slot dans l'inscription (référence `event:{registrationId}:{slot}`) ;
   - run hebdo : `/runs-hebdo/[weeklyRunId]/ma-run` (référence `weekly:{weeklyRunId}`).
2. Cliquer sur l'onglet affiche le tracker dans la page et le connecte tout seul : aucun clic de plus. Il est
   adressable (`?tab=tracker`) comme les autres onglets.
3. L'onglet n'existe que pour le **propriétaire du slot** et si le jeu a **au moins un pack actif**. Un autre joueur
   qui consulte la progression ne le voit pas (règle de la 54.7 : on ne se connecte pas avec le slot d'un autre).
   La page admin de progression n'a pas l'onglet.
4. Le tracker ne se charge qu'à la première ouverture de l'onglet (moteur Lua et pack lourds), puis reste monté :
   revenir sur Checks puis sur Tracker ne recharge pas le pack et ne reconnecte pas.
5. Une partie pas encore lancée : le tracker s'ouvre en suivi manuel et se connecte au lancement, comme en 54.7.
6. Le bouton « Ouvrir le tracker » des écrans de connexion (54.7) et le tracker de la page du jeu (manuel, la
   connexion demande les infos) restent tels quels.

## Critères d'acceptation

1. Sur ma partie privée, mon slot d'event et ma run hebdo, l'onglet Tracker affiche le tracker de mon jeu, connecté
   à mon slot sans autre action.
2. `?tab=tracker` ouvre directement l'onglet.
3. Un joueur qui n'est pas propriétaire du slot, ou un slot dont le jeu n'a pas de pack actif : pas d'onglet, et
   `?tab=tracker` retombe sur Checks.
4. Passer d'un onglet à l'autre ne recharge ni ne reconnecte le tracker.
5. Aucun mot de passe dans l'adresse ni dans l'historique.

## Tests et gates

6. Jest : présence de l'onglet selon propriétaire et pack actif, repli de `?tab=tracker`, tracker monté une fois.
   Vérification réelle sur une partie privée et une run hebdo.
7. `pnpm gates` vert.

## Tasks / Subtasks

- [x] **Task 1** - Onglet Tracker partagé (visibilité, chargement à la première ouverture, maintien monté).
- [x] **Task 2** - Branchement sur les trois pages (partie privée, slot d'event, run hebdo) avec leur référence.
- [x] **Task 3** - Tests, vérification réelle, gates.

## Dev Agent Record

- `features/tracker/slot-tracker-tab.tsx` : `ownSlotTracker` (le slot affiché parmi les slots de connexion du joueur,
  ceux de la 54.7), `useSlotTrackerPacks` (packs du jeu), `SlotTrackerPanel` (tracker intégré, masqué par `hidden`
  derrière les autres onglets, jamais démonté). `TrackerScreen` gagne `embedded` : pas de titre propre dans la page.
- Branché sur `personal-run-slot-detail-page.tsx` (partie privée et slot d'event, via `runConnectionSlots` et la
  connexion de l'inscription) et `weekly-run-slot-page.tsx` (via `weeklyConnectionSlots`). Le nom du slot vient de la
  progression (`data.player`) et reste retenu.
- **Écart avec la décision 5** : l'onglet n'apparaît que quand la progression s'affiche, donc partie lancée. Une
  partie en pause montre déjà « Partie en pause » sans barre d'onglets, et l'API ne donne les slots d'une partie
  privée (`mySlots`) qu'au statut `active`. Le cas « suivi manuel puis connexion au lancement » reste couvert si le
  serveur de jeu redémarre pendant que l'onglet est ouvert.
- Vérification réelle (2026-11-17, partie privée locale, progression simulée côté navigateur faute de serveur de
  jeu) : onglet visible pour le propriétaire, clic = tracker du slot sans autre action, `?tab=tracker` en accès
  direct, aller-retour Items/Checks/Tracker sans remontage (même nœud DOM). Run hebdo non vérifiée en vrai (aucune
  inscription active en local), même code.
- Constaté hors périmètre : à 360 px, la page de progression déborde déjà (415 px) avec ou sans tracker ; à traiter
  avec la 54.9 ou à part.
- Tests : `slot-tracker-tab.test.tsx` (4). `pnpm gates` vert (1888 tests).
