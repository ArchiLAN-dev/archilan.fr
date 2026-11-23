# Story 56.7 - Réglage automatique des options de taille

**Status:** review
**Epic:** 56 - Équilibrage des parties (`_bmad-output/planning-artifacts/epics/epic-56-equilibrage.md`)
**Date:** 2026-11-21
**Issue:** #1180
**Dépend de:** 56.5 (durée cible, test joueur).

## Story

En tant que joueur dont le monde dépasse la durée cible,
je veux qu'on me propose une valeur d'option qui ramène mon monde à la bonne taille,
afin de ne pas avoir à deviner quelle option change quoi.

## Décisions

1. **Options candidates** : options numériques (`Range`) d'un jeu, désignées par un admin dans la curation comme
   « change la taille du monde ». Aucune détection automatique au départ.
2. **Recherche** : par dichotomie sur une option à la fois, avec des tests de slot, jusqu'à une durée estimée dans
   ±10 % de la cible, ou au plus 6 générations.
3. **Proposition** : « avec `nombre_de_jetons` à 40 au lieu de 120 : environ 3 h 50 ». Le joueur accepte (la valeur
   est appliquée à son YAML) ou ignore.
4. La recherche tourne en tâche de fond (Messenger) et compte dans la limite de tests de 56.5.

## Critères d'acceptation

1. Un admin désigne les options de taille d'un jeu.
2. Pour un slot au-delà de la cible, la recherche propose une valeur dans ±10 % de la cible, ou dit qu'elle n'a pas
   trouvé en 6 générations.
3. Rien n'est appliqué sans l'accord du joueur.

## Tests et gates

4. Unitaires de la dichotomie (passerelle de test simulée, fonction de taille monotone ou non). Fonctionnels :
   désignation admin, proposition, acceptation. Front.
5. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Désignation des options de taille dans la curation d'un jeu.
- [x] **Task 2** - Recherche en tâche de fond, résultat stocké avec le slot.
- [x] **Task 3** - Proposition et acceptation côté joueur.
- [x] **Task 4** - Tests et gates.

## Dev Agent Record

### Implémentation

- **Désignation (Task 1)** : colonne `game.size_options` (5 options au plus, numériques seulement, dans l'ordre où
  la recherche les essaie). `PUT /api/v1/admin/games/{id}/size-options`, carte « Options de taille » dans l'onglet
  apworld. Les options sont passées à l'éditeur du joueur avec les préréglages.
- **Recherche (Task 2)** : entité `SizeSearch` (table `size_search`, une par joueur et par jeu, `round` écarte les
  messages d'une recherche relancée). Logique pure : le minimum de l'option d'abord ; plus court que la cible,
  l'option grossit le monde et la recherche coupe l'écart en deux entre le minimum et la valeur du joueur ; encore
  trop long, le maximum (option qui réduit le monde) ; sans effet ou en échec, l'option suivante. Arrêt dans ±10 %
  de la cible (proposition) ou après 6 générations. La valeur du joueur n'est jamais retestée.
- **Tâche de fond** : `RunSizeSearchStepJob` sur le worker async, relancé avec un délai pendant une génération
  (comme 9.42). Chaque génération prend un test du quota de 9.57 ; quota atteint, la recherche patiente (1 min, 20
  fois au plus) au lieu d'échouer, sinon elle s'arrêterait presque toujours au 4e ou 5e essai.
- **YAML** : `YamlOptionValue` lit la valeur du joueur (pondérée = la plus probable, `random` = défaut) et réécrit
  l'option seule, mappings vides gardés (`{}` reste un mapping).
- **Proposition (Task 3)** : sous le résultat d'un test au-delà de la cible, bouton « Trouver un réglage plus
  court » ; « Avec X à 20 au lieu de 46 : environ 20 h (cible 19 h) » ; « Appliquer à mon YAML » passe par le même
  chemin qu'un préréglage (rien n'est enregistré sans le bouton de sauvegarde du joueur), « Ignorer ».
- La cible de 56.5 est extraite dans `YamlTestTarget`, partagée par le test et la recherche.

### Fichiers

- API : `SizeSearch`, `SizeSearchRepositoryInterface`, `DoctrineSizeSearchRepository`, `Game` (+ `sizeOptions`),
  `StartSizeSearch`, `SizeSearchStart`, `SizeSearchOutcome`, `RunSizeSearchStepJob(+Handler)`, `SizeSearchQuery`,
  `YamlOptionValue`, `YamlTestTarget`, `GameYamlTestQuery`, `AdminGameLibrary`, `AdminGameLibraryController`,
  `SizeSearchController`, sélections de jeux (inscription, partie privée), `NullRunnerGateway` (taille par YAML),
  migration `Version20261123100004` ; tests `SizeSearchTest` (unitaire et fonctionnel), `YamlOptionValueTest`.
- Front : `size-search-api.ts`, `size-search.tsx`, `admin-game-size-options.tsx`, `yaml-option-editor`,
  `events-api`, `slot-yaml-gate`, `personal-run-slot-yaml-page`, `personal-runs-api`, `admin-games-api`,
  `admin-game-apworld-tab`, `archipelago-yaml` ; tests associés.
