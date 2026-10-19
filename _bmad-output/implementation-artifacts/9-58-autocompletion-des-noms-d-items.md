# Story 9.58: Autocomplétion des noms d'items dans les éditeurs de YAML

**Status:** review
**Epic:** 9 - Sélection des jeux et YAML
**Date:** 2026-10-19
**Origine :** demande de Jean le 2026-10-19, en préparant une hebdo Hollow Knight où `start_hints` révèle toute la
progression : 32 noms d'items saisis un par un, sans aide ni contrôle.

## Story

En tant que joueur ou admin qui configure un jeu,
je veux que les options qui attendent des noms d'items proposent les items du jeu, et ses groupes,
afin de ne plus recopier des noms exacts depuis le wiki ni découvrir une faute de frappe à la génération.

## Contexte

La story 4.14 a fait l'équivalent pour les lieux : `introspect_options.py` (image archipelago) renvoie la liste
statique `locations` (clés de `location_name_to_id`), l'orchestrateur l'expose, le client PHP
(`archilan/orchestrateur-client`, `apworlds()->getLocations`) la lit, l'API la range sur le jeu
(`Game::recordLocationNames`, colonne `location_names`) et la passe aux éditeurs (`locationNames`), qui branchent
`LocationAutocompleteInput` sur `priority_locations`, `exclude_locations` et `start_location_hints`
(`LOCATION_OPTION_KEYS` dans `yaml-option-editor.tsx`).

Rien de tel pour les items. Les options concernées :

- listes de noms : `start_hints`, `local_items`, `non_local_items` ;
- dictionnaire nom → nombre : `start_inventory` (et `start_inventory_from_pool`) ;
- `plando_items` : les items de chaque bloc ;
- `item_links` : `item_pool`, `local_items`, `non_local_items`, `replacement_item`.

Archipelago accepte aussi un **groupe** d'items là où il attend un nom (`item_name_groups` du monde, par exemple
« Skills », « Dreamers », « Stags » pour Hollow Knight, plus le groupe implicite « Everything »).

## Acceptance Criteria

1. **Introspection.** `introspect_options.py` renvoie en plus `items` (clés triées de `item_name_to_id`) et
   `itemGroups` (`{groupe: [items]}` depuis `item_name_groups`), avec le même repli que les lieux (liste vide si le
   monde ne les expose pas) et sans casser les consommateurs actuels de la sortie.
2. **Transport.** L'orchestrateur expose les items et les groupes d'un apworld comme il expose ses lieux ; le client
   PHP gagne la méthode de lecture correspondante (nouvelle version du paquet, `version` du `composer.json` bumpée
   avant le tag). Un orchestrateur ancien qui ne les connaît pas renvoie une liste vide, sans erreur côté API.
3. **Stockage.** Le jeu garde `itemNames` et `itemGroups` (colonnes JSON nullables, migration), remplis partout où les
   lieux le sont : envoi d'un apworld (`AdminGameLibrary`), promotion d'un candidat (`PromoteApworldCandidate`),
   rattrapage. La commande de rattrapage des lieux (`app:games:backfill-locations`) remplit aussi les items, ou une
   commande sœur le fait.
4. **Éditeurs.** Les trois écrans (`slot-yaml-gate`, `personal-run-slot-yaml-page`, `admin-weekly-template-form`)
   reçoivent `itemNames` / `itemGroups` comme ils reçoivent `locationNames`, et l'éditeur branche l'autocomplétion sur
   les options de la liste ci-dessus : suggestions filtrées à la frappe, groupes affichés à part (« groupe, 18
   items »), saisie libre toujours possible (un nom absent de la liste statique reste accepté).
5. **Contrôle.** Dans les champs d'items, un nom ni item ni groupe connu est signalé en avertissement (pas bloquant :
   la liste est statique, un monde peut créer des items selon ses options). `checkImportedYaml` (story 9.57) avertit
   de même sur ces options à l'import.
6. **Jeu sans liste.** Sans items introspectés (jeu pas encore rattrapé, monde qui ne les expose pas), les champs
   restent des champs libres comme aujourd'hui, sans avertissement.
7. **Tests** : sortie d'introspection (items, groupes, monde sans items) ; lecture et stockage côté API ; éditeur
   (suggestions, groupes, avertissement) ; import ; gates verts dans chaque dépôt touché.

## Tasks / Subtasks

- [x] Image archipelago : `introspect_options.py` (items, groupes) + test (AC 1)
- [x] Orchestrateur : exposition des items et groupes + test, nouvelle version (AC 2)
- [ ] Client PHP : lecture des items et groupes, version bumpée, tag (accord de Jean) (AC 2)
- [x] API : colonnes + migration, enregistrement aux trois points, rattrapage, passage aux éditeurs (AC 3, 4)
- [x] Frontend : autocomplétion des champs d'items, groupes, avertissements, import (AC 4, 5, 6)
- [ ] Déploiement : image archipelago, orchestrateur, puis API ; rattrapage du catalogue

## Dev Notes

- Quatre dépôts : archipelago et orchestrateur sont des dépôts séparés (non sous-modulés), le client PHP aussi. Ordre
  de livraison : archipelago, orchestrateur, client, puis le monorepo, qui doit tolérer l'absence des items tant que
  l'orchestrateur n'est pas à jour (AC 2, AC 6).
- Taille : certains mondes ont des milliers d'items. La liste voyage avec la réponse de l'éditeur comme les lieux ;
  vérifier le poids sur les plus gros mondes avant de choisir entre la réponse actuelle et une requête à part.
- Un monde peut ajouter des items selon ses options (items d'événement, items créés à la génération) : la liste est
  une aide, jamais une validation bloquante (même règle que les lieux, story 4.14).
- À vérifier pendant l'implémentation : qu'Archipelago accepte bien un nom de groupe dans `start_hints` (la story
  9.57 laisse la question ouverte), pour savoir si l'éditeur doit proposer les groupes sur cette option.
- Parité des deux scripts d'import d'Archipelago (mémoire « archipelago-script-parity ») : un monde isolé n'hérite pas
  des imports collatéraux, l'accès à `item_name_groups` doit suivre le même chemin que `location_name_to_id`.

## Dev Agent Record

### Implementation Notes

- **Archipelago** (`feature/story-9-58-noms-d-items`) : module `world_names.py` (lieux, items, groupes sans
  « Everything »), copié dans l'image et ajouté au garde-fou d'import du build. Vérifié sur Hollow Knight dans l'image
  actuelle : 249 items, 39 groupes (Dreamers, Skills, Charms...), 37 Ko de sortie. Le poids reste raisonnable.
- **Orchestrateur** (`feature/story-9-58-noms-d-items`) : `GET /apworlds/{hash}/items` lit le fichier d'introspection
  déjà stocké (sortie brute du script) : rien à changer au stockage. Un fichier écrit avant l'image à jour donne des
  listes vides. Doc Swagger régénérée.
- **Client PHP** (`feature/story-9-58-noms-d-items`) : `ApworldsClient::getItems()` → `ApworldItems`, version
  `1.15.0` dans `composer.json`. **Tag à poser (accord de Jean)**, puis `composer update archilan/orchestrateur-client`
  dans l'API : d'ici là, les gates locaux tournent avec le client recopié dans `vendor/`.
- **API** : colonnes `game.item_names` / `game.item_groups` (migration `Version20261019110000`), port
  `RunnerGatewayInterface::fetchItems` (un orchestrateur ancien répond 404 : listes vides, log `info`), réponse
  normalisée par `ApworldIntrospectionNormalizer::items`. Enregistré à la promotion d'un candidat (sans bloquer la
  promotion si les items manquent), à la régénération du template et par `app:games:backfill-locations`. Exposé avec
  les lieux aux trois écrans.
- **Front** : `ItemCatalogContext` + `ItemNameInput` sur `start_hints`, `local_items`, `non_local_items`, les clés de
  `start_inventory`, les items de `plando_items`, l'`item_pool`, les items locaux / non locaux et l'item de
  remplacement des `item_links`. Groupes proposés d'abord avec leur taille, puis « Everything », puis les items. Un nom
  inconnu est signalé sous le champ, jamais refusé. L'import (9.57) avertit de même.
- **Empilement** : la branche part de celle de la 9.57 (import), dont l'AC 5 dépend.
- Non vérifié : qu'Archipelago accepte un nom de groupe dans `start_hints` (les groupes y sont proposés comme
  ailleurs ; la génération de test de la 9.57 permet de le vérifier).

### Déploiement (dans l'ordre)

1. Image archipelago (nouvelle introspection), 2. orchestrateur, 3. tag du client `v1.15.0` puis mise à jour du lock
de l'API, 4. API (migration) et front, 5. `app:games:backfill-option-types --reintrospect` puis
`app:games:backfill-locations` pour remplir le catalogue existant.
