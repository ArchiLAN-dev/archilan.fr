# Story 52.7 - Carte des dépendances (avant l'événement)

**Status:** review
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

- [x] **Task 1** (AC 1, 2) - Lecture des sphères depuis la génération, calcul des dépendances à risque.
- [x] **Task 2** (AC 3) - Comparaison de générations et choix.
- [x] **Task 3** (AC 6) - Vue admin, gates verts, Dev Agent Record.

## Dev Agent Record

**Implémenté le 2026-11-09** (branche empilée sur 52.6).

- **Lecture** : le spoiler de chaque session générée de l'événement, pris dans l'archive de génération sur MinIO
  (`SessionSpoilerArtifactReaderInterface`, comme le téléchargement du spoiler). `SpoilerPlaythrough` lit la
  section « Playthrough » ; une ligne est coupée sur les noms des joueurs connus (les noms peuvent contenir espaces,
  deux-points et parenthèses), le plus long d'abord. La sphère 0 (inventaire de départ) est ignorée. Pas de
  playthrough (spoiler de niveau < 2) : la génération est marquée « spoiler sans playthrough ».
- **Risque** (`DependencyRisk`, pur) : l'événement est coupé en demi-journées (midi et minuit, heure de Paris). Un
  objet de A est à risque quand il est dans le monde de B, dans les N premières sphères où A reçoit quelque chose
  (N = 3 par défaut, de 1 à 20), et qu'une demi-journée a A présent et B absent. Sans créneau, on est là tout
  l'événement. Deux slots d'une même inscription ne dépendent jamais l'un de l'autre.
- **Vue** : `GET /api/v1/admin/events/{id}/dependency-map?spheres=N` (staff) : `declared`, et par session générée
  son statut, l'état du playthrough, le score (nombre d'objets à risque) et les paires « Alice : 2 objets chez Bob
  (Hookshot, Bow), pas là : sam. matin, sam. après-midi ». Sans créneau déclaré : la vue le dit, aucun spoiler
  n'est lu, aucun risque.
- **Comparaison** : il n'existe pas de régénération d'une session (generated -> launching seulement, archive
  unique par session). Une nouvelle génération est donc une nouvelle session de l'événement depuis le constructeur
  (mêmes inscriptions, nouvelle seed) ; la carte compare toutes les sessions générées et marque « Meilleure » la plus
  basse quand elle est seule à ce score. Le choix est le lancement de la session retenue, depuis son détail.
  Aucune régénération automatique, aucune modification des YAML.
- **Front** : `features/dependencies/`, panneau « Carte des dépendances » sur la page Sessions de l'événement,
  avec le choix du nombre de sphères.
- **Tests** : unitaires `DependencyRiskTest` (8 : lecture du playthrough, absence de playthrough, demi-journées,
  présences qui se recouvrent ou non, sans créneaux, seuil de sphère, même personne) ; fonctionnel
  `DependencyMapTest` (3 : droits, scores et paires, sans créneaux) ; front `dependency-map.test.tsx` (4).
- **Gates** : API 3744 tests (ParaTest), phpstan, cs-fixer, DDD, rector ; front typecheck, lint, 1493 tests, build.
