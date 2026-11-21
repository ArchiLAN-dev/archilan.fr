# Story 56.4 - Simulateur de partie

**Status:** ready-for-dev
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

- [ ] **Task 1** - Vérifier, dans le spoiler produit par notre image, la présence de la section des emplacements et
  son format ; sinon l'extraire avec le script de 56.1.
- [ ] **Task 2** - Domain `PartySimulation` et ses tests.
- [ ] **Task 3** - Application : assemblage des entrées, cache du résultat avec la génération à blanc.
- [ ] **Task 4** - Front : colonnes du rapport et slot critique.
- [ ] **Task 5** - Gates.
