# Story 56.8 - Prévision en direct et calibration

**Status:** review
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

- [x] **Task 1** - Requête de prévision à partir de `session_slot` et du fil (avec des requêtes bornées, cf. hotfix
  v1.0.1 sur le fil des sessions).
- [x] **Task 2** - Signalement « à libérer » dans le hub admin.
- [x] **Task 3** - Enregistrement du prévu et du réel en fin de session, écart par jeu.
- [x] **Task 4** - Tests et gates.

## Dev Agent Record

### Implémentation

- **Prévision (Task 1)** : `EventForecastQuery` sur `GET /api/v1/admin/events/{id}/forecast`. Session en cours de
  l'event (la plus récente en `running`), par slot : checks restants (`locations_total - checks_done`) au rythme de
  sa dernière heure de jeu, c'est-à-dire l'heure qui précède son dernier check (`last_check_at`), bornée par le début
  de la session. Un slot à l'arrêt garde ainsi son rythme de jeu au lieu de tomber à zéro. Moins de 5 checks ou
  moins de 10 minutes dans la fenêtre : repli sur le rythme estimé de 56.2 (jeu x facteur joueur). Le fil n'est lu
  que pour une session, sur l'index `(session_id, occurred_at)`.
- **Signalement (Task 2)** : « à libérer » quand la fin estimée dépasse la fin de l'event et que le monde du slot
  détient encore des objets de progression des autres (`hosted_progression` de 56.1 moins ceux déjà envoyés d'après
  le fil). Aucune action automatique : le panneau renvoie vers « Run et sessions » (release, Passeur).
- **Calibration (Task 3)** : colonne `session_slot.estimated_hours`, figée par `MeasureSessionSlots` à la
  génération (taille mesurée x rythme estimé des joueurs du slot). Le réel se déduit de colonnes déjà gardées
  (`goal_reached_at - COALESCE(first_launched_at, started_at)`, comme 56.2), il n'est donc pas recopié. Le panneau
  « Prévu et réel » de l'event liste les slots arrivés au goal et l'écart moyen ; la fiche admin du jeu affiche
  l'écart moyen sur tous ses slots, sous le rythme estimé.
- Affichage sur la page Équilibre de l'event (hub, section Jour J), au-dessus du rapport de génération à blanc,
  rafraîchi chaque minute.

### Fichiers

- API : `SessionSlot` (+ `estimatedHours`), `MeasureSessionSlots`, `SlotForecastQueryInterface`,
  `DbalSlotForecastQuery`, `EventForecastQuery`, `EventForecastController`, `AdminGameLibrary`
  (`durationCalibration`), `services.yaml`, migration `Version20261123100005` ; tests `EventForecastTest`,
  `MeasureSessionSlotsTest`.
- Front : `event-forecast-api.ts`, `event-forecast.tsx`, page `equilibre`, `admin-game-editor` (`CalibrationNote`),
  `admin-games-api` ; test `event-forecast.test.tsx`.
