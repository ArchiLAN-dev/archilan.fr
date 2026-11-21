# Story 56.8 - Prévision en direct et calibration

**Status:** ready-for-dev
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1181
**Dépend de:** 56.2 (rythmes), 56.4 (simulation, pour comparer prévu et réel).

## Story

En tant qu'admin pendant un event,
je veux voir à quelle heure chaque slot devrait finir au rythme observé et quels slots retiennent des objets,
afin d'agir à temps (release, Passeur), et que les estimations s'améliorent à chaque partie.

## Décisions

1. **Prévision en direct** : pour un slot en cours, heure de fin estimée = maintenant + checks restants ÷ rythme
   observé sur la dernière heure de jeu (repli sur le rythme estimé de 56.2 si trop peu de checks). Calculée à partir
   des données du bridge déjà reçues ; rien de nouveau n'est demandé au bridge.
2. **Suggestion** : un slot dont l'heure de fin estimée dépasse la fin de l'event, et qui détient des objets de
   progression d'autres slots encore non trouvés, est signalé « à libérer » avec les actions existantes (release,
   Passeur de l'épic 52). Aucune action automatique.
3. **Calibration** : à la fin d'une session, on garde par slot la durée estimée (dernière génération à blanc ou
   estimation au lancement) et la durée réelle. Le rapport d'équilibre d'un event passé affiche l'écart, et la
   moyenne des écarts par jeu apparaît sur la fiche admin du jeu.

## Critères d'acceptation

1. Le hub admin d'un event en cours affiche, par slot, l'heure de fin estimée et le signalement « à libérer »
   quand il s'applique.
2. Une session terminée garde le prévu et le réel par slot, et le rapport les compare.
3. La fiche admin d'un jeu montre l'écart moyen entre prévu et réel.

## Tests et gates

4. Fonctionnels : prévision (rythme observé, repli), signalement, enregistrement de fin, écart moyen. Front.
5. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** - Requête de prévision à partir de `session_slot` et du fil (avec des requêtes bornées, cf. hotfix
  v1.0.1 sur le fil des sessions).
- [ ] **Task 2** - Signalement « à libérer » dans le hub admin.
- [ ] **Task 3** - Enregistrement du prévu et du réel en fin de session, écart par jeu.
- [ ] **Task 4** - Tests et gates.
