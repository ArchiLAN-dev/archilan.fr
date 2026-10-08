# Story 32.21: La timeline ne disparaît plus quand le dernier jour n'a pas de check

**Status:** review
**Epic:** 32 - Recaps
**Date:** 2026-10-08

## Story

En tant que joueur d'une partie privée async,
je veux que le graphique de l'onglet Progression reste affiché même quand le jour en cours n'a encore
vu aucun objet trouvé,
afin de ne pas perdre le déroulé de la partie (et le sélecteur de jour) au premier indice du matin.

## Contexte

Signalé par Jean le 2026-10-08 : « le graphique en temps réel sur la page de progression d'une partie privée ne
s'affiche plus ». Reproduit sur « La giga async » en prod : le 8 octobre, un seul indice à 09:27, premier objet
trouvé à 19:58. Entre les deux, l'onglet Progression ne montrait plus ni graphique, ni sélecteur de jour, ni
journal ; tout est revenu à 19:58.

Cause (`run-timeline.tsx`) : la timeline est paginée par jour, et le jour par défaut est le dernier jour ayant un
évènement avec un expéditeur - indices et objectifs compris (story 32.12). La courbe, elle, ne compte que les
objets trouvés (`build-checks-series.ts`). Un dernier jour fait seulement d'indices ou d'objectifs donnait une
série vide, et le composant renvoyait `null` : tout disparaissait, y compris le moyen de revenir à la veille.

Touche le direct (onglet Progression) comme le récap, qui partagent `RunTimeline`.

## Critères d'acceptation

1. Les jours de la pagination sont ceux qui ont au moins un objet trouvé : le jour par défaut est le dernier jour
   où la courbe a quelque chose à tracer. Les indices et objectifs de ces jours restent au journal et en marqueurs.
2. Une partie sans aucun objet trouvé ne rend toujours rien (comportement inchangé).
3. Tests : dernier jour avec un seul indice, dernier jour avec un seul objectif, partie sans objet trouvé.

## Tâches

- [x] **Task 1** (AC 1, 2) - `run-timeline.tsx` : jours calculés sur les évènements `item-received`.
- [x] **Task 2** (AC 3) - `run-timeline.test.tsx` (échoue sans le correctif, vérifié).
- [x] **Task 3** - `pnpm gates` vert.

## Dev Notes

- Un jour qui n'aurait que des indices sort de la pagination : ses indices ne sont plus consultables dans le
  journal de la timeline. Cas marginal (un indice sans aucun objet trouvé de la journée), accepté pour garder un
  seul critère de jour, celui de la courbe.

## Dev Agent Record

### File List

- `frontend/src/features/recap/run-timeline.tsx`
- `frontend/src/features/recap/run-timeline.test.tsx`
