# Story 38.33 - Voir qu'un test de génération tourne vraiment

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-11-01
**Origine :** Super Mario RPG (nouveau jeu, SMRPG-0.3 importé à la main le 2026-11-01). La fiche affiche « Nouvelle
version en test » sans rien qui montre qu'une génération tourne. Jean veut voir un vrai test en cours, et non une
attente.
**Dépôts :** `orchestrateur`, `archilan-orchestrateur-client`, `archilan.fr` (API + frontend).

## Constat

- Chez l'orchestrateur, un test lancé passe en `pending` tout de suite, puis attend une place dans le budget de
  conteneurs partagé avec les tests de slot (`preflightSem`) avant de générer. « pending » mélange donc la file
  d'attente et la génération en cours.
- L'API n'expose pour une nouvelle version que le statut du candidat (`testing`) : ni le statut du test chez
  l'orchestrateur, ni depuis quand il tourne.
- Si l'orchestrateur n'a aucun test en cours pour la nouvelle version (redémarrage, run perdu), la fiche reste
  « en test » jusqu'au délai de 30 minutes, sans moyen de relancer.
- La page interroge déjà l'API toutes les 10 s tant qu'une version est en test : il ne manque que l'information.
- Pour un nouveau jeu, le texte « le jeu sert toujours sa version actuelle » est faux : il n'en a pas.

## Décisions

- Orchestrateur : le verdict `pending` porte `startedAt` (RFC3339), posé quand le run obtient sa place et commence
  la génération ; vide tant qu'il attend. Effacé au verdict.
- Client PHP : `ApworldPreflight::$startedAt` (nullable), version mineure.
- API : la nouvelle version expose `test: {state, startedAt}` avec `state` parmi `running` (pending + startedAt),
  `queued` (pending sans startedAt), `idle` (aucun test en cours chez l'orchestrateur, ou orchestrateur muet).
- Frontend, carte « Nouvelle version » en test :
  - `running` : spinner, « Test de génération en cours depuis N min » (minutes calculées dans un callback de
    rafraîchissement, jamais pendant le rendu) ;
  - `queued` : « En file d'attente : un autre test occupe le runner » ;
  - `idle` : « Aucun test en cours pour cette version » et le bouton « Relancer le test » (même action que pour
    une version rejetée, ROM comprise).
- Même visuel sur la carte « Version en ligne » quand son test tourne (aujourd'hui un simple texte).
- Texte d'un nouveau jeu : « Le jeu n'a pas encore de version en ligne » au lieu de « sert toujours sa version
  actuelle ».

## Critères d'acceptation

1. Un test qui génère montre un spinner et sa durée ; un test en file d'attente le dit ; la fiche se met à jour
   seule (polling existant de 10 s).
2. Une nouvelle version en test sans run chez l'orchestrateur affiche « Aucun test en cours » et peut être
   relancée ; l'API accepte la relance d'un candidat `testing` dans ce cas (pas pendant un run).
3. Le texte d'un nouveau jeu sans version en ligne ne parle plus de « version actuelle ».
4. Orchestrateur : `startedAt` posé à l'entrée dans la génération, absent en file, effacé au verdict ; tests.
5. Gates verts : `go test ./...`, tests du client, `composer gates`, `pnpm gates`.

## Tâches

- [x] Orchestrateur : `ApworldPreflight.StartedAt`, posé après l'acquisition de `preflightSem` (le run doit encore
  posséder le verdict), effacé par `storeApworldPreflight` ; tests ; swagger.
- [x] Client : `startedAt` dans `ApworldPreflight`, test, tag mineur.
- [x] API : `startedAt` dans le payload des verdicts, `test` dans `candidatePayload`, relance d'un candidat
  `testing` sans run en cours ; tests unitaires et fonctionnels.
- [x] Frontend : type guard, visuel running / queued / idle, bouton « Relancer le test » en `idle`, texte nouveau
  jeu ; tests jest.
- [x] Vérification locale : import d'un apworld, observer file d'attente puis génération puis verdict.

## Mise en prod

Orchestrateur, puis site (composer.lock sur le nouveau client). Sans le nouvel orchestrateur, l'API voit `queued`
pour tout test pending : dégradé acceptable, à éviter en déployant dans l'ordre.

## Dev Agent Record

- Orchestrateur (branche `feat/story-38-33-test-en-cours`, 94b84ab) : `ApworldPreflight.StartedAt` pose par `startRun` apres l'acquisition de `preflightSem` si le run possede encore le verdict pending ; efface par `markPending` et `applyVerdict` ; expose dans l'API ; 4 tests ; swagger ; `go test ./...` vert.
- Client (branche `feature/story-38-33-test-en-cours`, b50263c) : `ApworldPreflight::$startedAt`, version 1.18.0, 125 tests verts.
- API : enum `ApworldTestState` (running / queued / finished / idle) ; `startedAt` dans le payload des verdicts ; `apworldCandidate.test` pour un candidat en test ; relance d'un candidat `testing` seulement si aucun test n'est en cours (`Forbidden` sinon) ; `ApworldCandidate::retry` accepte `Testing`. `composer gates` vert.
- Frontend : `ApworldTestProgress` (spinner + duree via `useElapsedMinutes`, horloge lue dans des callbacks), cartes « Nouvelle version » (running / queued / finished / idle + « Relancer le test ») et « Version en ligne » ; texte d'un nouveau jeu ; `pnpm gates` vert (1359 tests).
- Verification locale (orchestrateur reconstruit) : import DD1 0.8.2 puis test, `pending` + `startedAt` pendant la generation, puis `passed` sans `startedAt`. L'etat `queued` n'a pas ete observe (runner libre).
- Reste : tag client v1.18.0 puis `composer.lock` de l'API dessus.
