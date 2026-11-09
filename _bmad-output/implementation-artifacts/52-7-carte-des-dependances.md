# Story 52.7 - Carte des dépendances (avant l'événement)

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-7-carte-des-dependances` (depuis `develop`)
**Dépend de:** 52.2 (créneaux). Indépendante du Passeur lui-même ; peut attendre.

## Story

En tant qu'orga,
je veux voir, avant le lancement, qui risque d'attendre qui selon les créneaux de chacun,
afin de régénérer la partie si les blocages prévus sont trop nombreux.

## Contexte (cartographie du 2026-11-09)

- Le spoiler donne les sphères de progression ; le bridge l'expose (`GET /slots/{slot}/spoiler`) une fois la partie
  lancée, la génération produit aussi la multidata lue par `read_multidata.py`.
- Créneaux de présence : 52.2.

## Décisions

1. **Modèle simple et explicite** : on ne simule pas le temps de jeu. Une dépendance est « à risque » quand un objet
   de progression d'un joueur A est dans le monde d'un joueur B, et qu'il existe une demi-journée où A est présent
   et B absent, dans les premières sphères de A (réglable, par défaut les 3 premières).
2. **Vue par génération** : un score (nombre de dépendances à risque) et la liste par joueur (« Alice : 3 objets
   chez Bob, qui n'arrive que dimanche »).
3. **Comparaison** : l'admin peut régénérer (nouvelle seed, mêmes YAML) et comparer les scores ; il retient une
   génération. Aucune régénération automatique.
4. Lecture seule sur la génération : aucune modification des YAML.

## Critères d'acceptation

1. Pour une génération d'événement, la vue liste les dépendances à risque par joueur et donne un score.
2. Sans créneaux déclarés, la vue l'indique et n'affiche aucun risque.
3. Deux générations se comparent par leur score ; l'admin choisit celle qui sera lancée.

## Tests et gates

4. Unitaires : détection d'une dépendance à risque (présences qui se recouvrent ou non, seuil de sphère).
5. Fonctionnel : la vue admin (droits, forme de la réponse).
6. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 2) - Lecture des sphères depuis la génération, calcul des dépendances à risque.
- [ ] **Task 2** (AC 3) - Comparaison de générations et choix.
- [ ] **Task 3** (AC 6) - Vue admin, gates verts, Dev Agent Record.
