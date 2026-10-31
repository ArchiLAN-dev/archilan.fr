# Story 47.3 - La mosaïque publique

**Status:** review
**Epic:** 47 - Multistream des participants (`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-10-31
**Branche:** `feature/epic-47-story-3-mosaique-publique` (depuis `develop`, indépendante de 47.1 et 47.2)

## Story

En tant que visiteur qui suit une LAN,
je veux regarder plusieurs joueurs en live côte à côte,
afin de suivre l'événement en multipov sans jongler entre les onglets.

## Contexte

- `/streams/[kind]/[id]` affiche `ParticipantStreams` en `showAll` : les cartes des chaînes (lives en tête) et un seul
  lecteur partagé, chargé au clic sur une carte. Sous `sm`, un clic ouvre twitch.tv (pas de lecteur).
- La bannière cookies de la story 7.5 a disparu depuis ; la règle restée en place est qu'**aucun lecteur Twitch ne se
  charge sans un clic** du visiteur. La mosaïque la respecte : elle ne s'ouvre que sur un clic.
- Lecture `participant-streams` (story 7.7) relue chaque minute, pas de changement API.

## Décisions

1. **Bascule « Liste / Mosaïque »** en tête du bloc, sur la page dédiée seulement (prop `mosaic`), à partir de 2 lives
   et sur écran `sm+`. Tous les types de session de la page en profitent (événement, partie, hebdo) : c'est le même
   composant, rien à restreindre.
2. **Mosaïque** : jusqu'à **4 lecteurs** côte à côte (1 en plein, 2 côte à côte, 3 ou 4 en 2 x 2), muets. Au départ,
   les 4 premiers lives. En mode mosaïque, un clic sur la carte d'un live l'ajoute à la mosaïque (en remplaçant le
   plus ancien si elle est pleine) ou l'en retire.
3. **En grand** : sous chaque lecteur, un bouton avec le pseudo passe ce stream en grand **avec le son** ; les autres
   restent en vignettes muettes dessous. « Revenir à la mosaïque » remet la grille.
4. Un live qui s'arrête sort de la mosaïque à la relecture suivante. Moins de 2 lives : retour à la liste (et à son
   lecteur unique).
5. La page `/streams` passe en `max-w-5xl` pour que 4 lecteurs restent lisibles.

## Critères d'acceptation

1. Sur `/streams/event/<id>` avec au moins 2 lives, un bouton « Mosaïque » affiche jusqu'à 4 lives côte à côte, muets.
2. Aucun lecteur ne se charge avant le clic ; pas de bascule sous `sm` ni avec moins de 2 lives.
3. En mosaïque, un clic sur une carte live l'ajoute ou la retire ; au-delà de 4, le plus ancien sort.
4. « En grand » met un stream en grand avec le son, les autres en vignettes ; retour possible à la grille.
5. Tests : fonctions de sélection (pures), rendu de la mosaïque et du mode grand.
6. `pnpm gates` vert ; vérification réelle.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4) - `participant-streams-mosaic.tsx` (sélection, grille, mode grand) ; bascule dans
  `ParticipantStreams` (prop `mosaic`) ; page `/streams` élargie.
- [x] **Task 2** (AC 5-6) - Tests, gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `participant-streams-mosaic.tsx` : `reconcileMosaic` (4 premiers lives au départ, puis les choix encore en live),
  `toggleInMosaic` (ajout ou retrait, le plus ancien sort au-delà de 4), `ParticipantStreamsMosaic` (grille muette,
  bouton « en grand avec le son » sous chaque lecteur, vignettes et « Revenir à la mosaïque » en mode grand).
- `ParticipantStreams` : prop `mosaic`, bascule « Liste / Mosaïque » (2 lives et écran `sm+`), en mosaïque un clic sur
  une carte live l'ajoute ou la retire, un clic sur une carte hors ligne revient à la liste avec ce lecteur.
- Page `/streams/[kind]/[id]` : `mosaic` activé, largeur `max-w-5xl`.
- Vérification réelle (Chrome) sur la démo 4.23 avec les inscrits de test : aucun lecteur avant le clic, mosaïque
  2 x 2 des 4 lives (le refus régie de la 47.1 ne joue pas ici, c'est le lecteur du joueur), passage en grand avec
  vignettes dessous.
- `pnpm gates` vert (1335 tests sur la base `develop`, sans les tests de 47.1 et 47.2).

### File List

- `frontend/src/features/streaming/participant-streams-mosaic.tsx` (nouveau)
- `frontend/src/features/streaming/participant-streams-mosaic.test.tsx` (nouveau)
- `frontend/src/features/streaming/participant-streams.tsx` (modifié)
- `frontend/src/app/(public)/streams/[kind]/[id]/page.tsx` (modifié)
