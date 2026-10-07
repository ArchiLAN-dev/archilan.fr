# Story 40.3: Pas de fausse sortie de BK au redémarrage du serveur

**Status:** review
**Epic:** 40 - Sortie de BK et notifications push
**Date:** 2026-10-06

## Story

En tant que joueur d'une partie privée,
je veux être prévenu de ma sortie de BK seulement quand elle a vraiment lieu en jeu,
afin de ne pas recevoir « Tu n'es plus bloqué » à chaque redémarrage du serveur.

## Contexte

Jean (2026-10-06) reçoit des notifications de sortie de BK quand le serveur redémarre. Review du code (40.1) :

- `SlotBlockRule::decide` garde l'épisode de BK ouvert quand l'état est inconnu (`reachable_now = null`). Or le
  bridge n'envoie `null` qu'à son démarrage (ses états de joueur naissent de la sauvegarde et sont ensuite mis à
  jour en place) : un épisode survit donc à l'arrêt du serveur.
- Au redémarrage, le serveur repart de la dernière sauvegarde : les checks faits après elle redeviennent
  atteignables, `reachable_now > 0`, et l'épisode (souvent vieux de plusieurs heures) notifie. Même effet si un
  calcul transitoire au démarrage du bridge ouvre un épisode qui se ferme plus de 2 minutes après.
- Rien ne peut changer en jeu pendant que le serveur est éteint : un épisode qui traverse un arrêt ne dit plus rien
  de fiable.

## Critères d'acceptation

1. Un état inconnu (démarrage du bridge) clôt l'épisode ouvert du slot **sans notification**.
2. Un épisode commencé avant le dernier arrêt de la session (`session.stoppedAt`) se clôt sans notification même si
   le signal de démarrage n'est pas arrivé.
3. Une vraie sortie de BK en cours de partie (au moins 2 minutes) notifie toujours, une fois.
4. Gates verts ; tests des trois cas.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `SlotBlockRule::decide` : inconnu + épisode = clôture silencieuse ; tests.
- [x] **Task 2** (AC 2, 3) - `SlotBlockTracker` : épisode antérieur au dernier arrêt = clôture silencieuse ; tests.
- [x] **Task 3** (AC 4) - Gates.

## Dev Agent Record

- Vérifié dans le bridge : les états de joueur naissent à son démarrage (depuis la sauvegarde) ou pour un nouveau
  slot, puis sont mis à jour en place ; `reachable_now = null` signifie donc « le bridge vient de démarrer ».
- `SlotBlockRule::decide` : `Unknown` + épisode ouvert = `CloseSilently` (au lieu de `Keep`). Couvre tous les
  redémarrages, `idle` compris.
- `SlotBlockTracker` : garde-fou, un épisode commencé avant `session.stoppedAt` ne notifie jamais. `stoppedAt` n'est
  posé que pour `stopped` / `failed` / `crashed` (pas `idle`) ; il n'a pas été étendu à `idle` car la page admin
  d'une session s'en sert pour calculer sa durée. Le cas `idle` repose sur le signal de démarrage du bridge.
- Non reproduit en local (aucune notification de ce type en dev) : cause déduite du code, à surveiller en prod.
- Fichiers : `api/src/Sessions/Domain/Service/SlotBlockRule.php`, `api/src/Sessions/Application/Service/SlotBlockTracker.php`,
  `api/src/Sessions/Domain/Entity/Session.php` (`getStoppedAt`), `api/tests/Unit/Sessions/SlotBlockRuleTest.php`,
  `api/tests/Unit/Sessions/SlotBlockTrackerTest.php`.
- `composer gates` vert (2 782 tests).
