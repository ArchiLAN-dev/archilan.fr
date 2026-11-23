# Story 56.4 - Simulateur de partie

**Status:** review
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1177
**Dépend de:** 56.3 (spoiler d'une génération à blanc), 56.2 (rythmes).

## Story

En tant qu'admin d'un event,
je veux voir à quelle heure chaque joueur finirait, combien de temps il passerait bloqué et après qui il attendrait,
afin de juger une partie sur son déroulé et pas seulement sur des tailles.

## Décisions

1. **Entrée** : le placement complet des objets (section des emplacements du spoiler, ou extraction par le script de
   56.1 si le spoiler ne la contient pas) et les règles d'accès résumées par les spheres du parcours
   (`SpoilerPlaythrough`).
2. **Modèle simple et explicable** : chaque joueur fait ses checks accessibles à son rythme estimé (56.2), dans
   l'ordre des spheres. Un objet de progression trouvé arrive aussitôt à son destinataire et ouvre sa sphere
   suivante. Un joueur sans check accessible est en BK jusqu'à la prochaine arrivée.
3. **Sorties par slot** : heure de fin estimée (goal), temps total en BK, et les deux slots dont il attend le plus.
   Pour la partie : heure de fin du dernier slot et slot critique.
4. Calcul pur dans le Domain (`PartySimulation`, sans I/O, sans horloge), alimenté par l'Application.
5. **Limite assumée et affichée** : le modèle ignore les déplacements dans un jeu, les hints et les choix des joueurs.
   C'est un ordre de grandeur pour comparer les slots, pas une prédiction à la minute.

## Critères d'acceptation

1. Sur un cas construit (3 slots, dépendances connues), la simulation donne les heures de fin et les temps de BK
   attendus.
2. Un slot dont le rythme est inconnu est simulé au rythme médian de la partie et marqué comme tel.
3. Le rapport d'équilibre affiche, par slot, l'heure de fin estimée, le temps en BK et ses dépendances principales,
   ainsi que le slot critique de la partie.

## Tests et gates

4. Unitaires du Domain sur des cas construits (chaîne, attente croisée, slot autonome). Fonctionnel du rapport. Front.
5. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Vérifier, dans le spoiler produit par notre image, la présence de la section des emplacements et
  son format ; sinon l'extraire avec le script de 56.1.
- [x] **Task 2** - Domain `PartySimulation` et ses tests.
- [x] **Task 3** - Application : assemblage des entrées, cache du résultat avec la génération à blanc.
- [x] **Task 4** - Front : colonnes du rapport et slot critique.
- [x] **Task 5** - Gates.

## Dev Agent Record

### Implémentation

- **Entrée (Task 1)** : le parcours du spoiler (« Playthrough », lu par `SpoilerPlaythrough` de 52.7) suffit : il
  donne, sphère par sphère, qui trouve un objet de progression et pour qui. La section des emplacements n'apporte
  rien de plus au modèle (elle ne dit pas l'accès), elle n'est pas lue. La génération à blanc force un spoiler de
  niveau 2 au moins (jamais montré aux joueurs) ; la mesure garde un parcours compact (`sphere`, `finder`,
  `receiver`) dans `balance_report.playthrough`.
- **Modèle (`PartySimulation`, Domain pur)** : le temps de jeu d'un slot (56.2) est coupé en parts égales, une de
  plus que les sphères où il reçoit un objet. La part k attend ses objets des sphères jusqu'à sa k-ième ; un objet
  qu'il trouve en sphère s part à mi-chemin de la part qui ouvre s. L'attente est du BK, imputée au slot dont l'objet
  arrive le dernier. Sortie : fin, BK, les deux slots attendus le plus ; fin de partie et slot critique (le dernier à
  finir).
- **Rythme inconnu** : le slot joue au rythme médian (emplacements par heure) de la partie, marqué `paceAssumed`.
  Sans aucun rythme connu, `simulation.status = no_pace` ; sans parcours (rapport d'avant 56.4), `missing`.
- **Rapport** : colonne « Déroulé simulé » (fin, BK, attend X), encadré « Fin de partie simulée » avec le slot
  critique et la limite du modèle. Un joueur voit son propre déroulé, pas le résumé de la partie.

### Fichiers

- API : `PartySimulation`, `SimulatedSlot`, `PartySimulationResult`, `BalanceReport` (+ `playthrough`),
  `RecordBalanceDryGeneration`, `RunBalanceDryGeneration`, `BalanceReportQuery`, migration `Version20261123100003` ;
  tests `PartySimulationTest` (chaîne, attente croisée, slot autonome), `BalanceReportTest`.
- Front : `balance-api.ts`, `balance-report.tsx` et son test.
