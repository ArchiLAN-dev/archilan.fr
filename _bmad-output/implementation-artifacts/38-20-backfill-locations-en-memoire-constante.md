# Story 38.20 - Le catalogue des jeux tient en mémoire

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-22
**Livraison :** hotfix v0.44.1 (depuis `main`), décision Jean 2026-10-22.
**Origine :** mise en prod v0.44.0 (2026-10-22).
- `app:games:backfill-locations` meurt sur `Allowed memory size of 134217728 bytes exhausted`.
- `/admin/catalogue` renvoie une 500 à chaque appel, même erreur, dans `JsonTypeConvert.php` (décodage d'une
  colonne JSON) : logs api-web de 18:58 à 19:06.

## Constat

Une entité `Game` porte, en plus de ses métadonnées, le résultat complet de l'introspection de son apworld :
YAML par défaut, types d'options, noms de lieux et, depuis la 9.58 (v0.44.0), noms d'objets et groupes d'objets.
Prod : 855 jeux, 15 Mo **compressés** par Postgres pour ces colonnes ; décodés en tableaux PHP, plusieurs fois plus.

Tout code qui charge le catalogue entier en entités décode donc tout, et dépasse 128 Mo depuis l'ajout des objets :

| Chemin | Charge tout le catalogue via |
|---|---|
| `/admin/catalogue` | `CatalogSyncStatusQuery` (`findAllSortedByName`) |
| Run perso, « mes slots » (joueurs) | `PersonalRunGameSelection::getMySlots` (`findByAvailabilitiesSortedByName`) |
| Sélection des jeux d'un event (admin) | `AdminEventGameSelection` |
| Vérification des mises à jour (worker) | `CheckApworldUpdatesService` |
| Backfills (CLI) | `BackfillGameLocations`, `BackfillGameOptionTypes`, etc. |

Aucun de ces chemins de liste n'a besoin de l'introspection, sauf pour les quelques jeux qu'un joueur a déjà dans
ses slots (éditeur YAML).

Mitigation en prod en attendant : `memory_limit = 1G` monté dans api-web et api-worker.

## Décision

L'introspection sort de la table `game` vers `game_introspection`, une entité `GameIntrospection` que `Game`
référence en association paresseuse (côté propriétaire, donc chargée seulement au premier accès). Les getters et
les méthodes d'écriture de `Game` ne changent pas de signature : aucun appelant ne bouge, et tout chemin futur qui
liste les jeux en profite. Une projection DBAL par chemin a été écartée : quatre requêtes à maintenir, de la logique
de domaine (`computeApworldUpdateStatus`, `GenerationFiles`) à dupliquer, et le prochain chemin de liste aurait
rechuté.

`dict_option_values` reste sur `game` : c'est une curation admin, petite, pas un résultat d'introspection.

## Critères d'acceptation

1. Nouvelle table `game_introspection` (`game_id` PK) : `default_yaml`, `option_types`, `location_names`,
   `item_names`, `item_groups`. `game.introspection_id` (unique, FK, `ON DELETE SET NULL`) la référence.
2. Migration : copie des données existantes (une ligne par jeu qui a au moins une de ces valeurs), lien posé,
   colonnes retirées de `game`. `down()` restaure tout.
3. `Game` : association paresseuse ; un jeu sans introspection n'a pas de ligne (getters à `null`) et la ligne est
   créée au premier enregistrement. Les getters et méthodes existants gardent leur comportement.
4. Charger tous les jeux ne charge aucune introspection : test fonctionnel sur l'état des objets paresseux.
5. `DbalGameCatalogQuery` lit `option_types` par jointure.
6. Backfills qui écrivent l'introspection de chaque jeu (`backfill-locations`, `backfill-option-types`) : parcours
   par identifiant et relâchement de chaque jeu après enregistrement, pour une mémoire indépendante du catalogue.
7. Notes de mise en prod du hotfix : la migration, puis retrait possible de la mitigation `memory_limit`.

## Tâches

- [x] Entité `GameIntrospection`, association sur `Game`, migration.
- [x] `DbalGameCatalogQuery`.
- [x] Repository : identifiants des jeux, relâchement d'un jeu ; backfills.
- [x] Tests (AC4, migration aller-retour, backfills), `composer gates`.
- [x] Mesure : `/admin/catalogue` et backfill sous 128 Mo sur un catalogue réaliste.
- [ ] Hotfix v0.44.1 : PR `main`, PR `develop`, CHANGELOG.

## Dev Agent Record

### Completion Notes

- `GameIntrospection` (table `game_introspection`, PK `game_id`) porte `default_yaml`, `option_types`,
  `location_names`, `item_names`, `item_groups`. `Game` la référence côté propriétaire (`game.introspection_id`,
  unique, FK `ON DELETE SET NULL`), donc en chargement paresseux (objets paresseux natifs de Doctrine). Cascade
  `persist`, `remove`, `detach`.
- Les getters et méthodes `record*` de `Game` gardent leur signature et leur comportement ; une ligne n'est créée
  qu'au premier enregistrement d'une valeur non vide (un jeu sans introspection n'en a pas).
- Repository : `findAllIdsSortedByName()` et `release(Game)`. `release` détache le jeu (et, par cascade, son
  introspection et sa synchro catalogue : une synchro restée gérée pointerait vers un jeu détaché et casserait le
  flush suivant) puis appelle `gc_collect_cycles()`. Sans ce dernier, le parcours grossissait encore d'environ
  13 Mo par tranche de 100 jeux : `Game` et `GameCatalogSync` forment un cycle que PHP ne libère qu'au passage de
  son collecteur, trop rare pour un parcours de quelques centaines de jeux.
- `BackfillGameOptionTypes` (sur tout le catalogue ou un seul slug) et `BackfillGameLocations` parcourent par
  identifiant et relâchent chaque jeu. Les autres backfills (`platforms`, `steam app ids`, `tutorials`,
  `declared files`, `deployed version`) n'écrivent pas l'introspection : ils listent des jeux désormais légers et
  restent tels quels.
- Chemins de liste vérifiés : catalog sync, vérification des mises à jour, sélection d'event, picker des runs
  (« mes slots ») ne lisent l'introspection que pour les jeux tenus par le joueur ; la fiche admin d'un jeu la lit
  pour ce seul jeu.
- Migration `Version20261022200000` : sur une copie de la base de dev (664 jeux), données identiques avant/après
  (empreinte md5 de YAML + options + lieux), retour arrière identique, aucun écart Doctrine sur `game` ni
  `game_introspection` (les autres écarts sont les faux positifs connus).
- Mesures sur cette copie, introspection gonflée à 8,9 Mo compressés (prod : 15 Mo) :
  - liste des 664 jeux : pic 15,9 Mo sous `memory_limit=128M` ; 114,9 Mo si toutes les introspections sont
    chargées (comportement d'avant) ;
  - parcours d'écriture de tout le catalogue avec `release` : pic 17,3 Mo, plat ; sans `release` : 115 Mo.
- Tests : `GameIntrospectionLazyLoadTest` (fonctionnel : aucune introspection chargée par une liste, aller-retour,
  pas de ligne tant que rien n'est enregistré, suppression en cascade, flush correct après `release`), vérifié
  rouge avec `fetch: 'EAGER'` ; backfills adaptés + test de `release`.
- Gates : phpstan, cs-fixer, ddd, rector OK ; phpunit 3209 OK (base isolée).

### File List

- `api/src/GameSelection/Domain/Entity/GameIntrospection.php` (nouveau)
- `api/src/GameSelection/Domain/Entity/Game.php`
- `api/src/GameSelection/Domain/Repository/GameRepositoryInterface.php`
- `api/src/GameSelection/Infrastructure/Doctrine/DoctrineGameRepository.php`
- `api/src/GameSelection/Infrastructure/Dbal/DbalGameCatalogQuery.php`
- `api/src/GameSelection/Application/Command/BackfillGameLocations.php`
- `api/src/GameSelection/Application/Command/BackfillGameOptionTypes.php`
- `api/migrations/Version20261022200000.php` (nouveau)
- `api/tests/Functional/GameIntrospectionLazyLoadTest.php` (nouveau)
- `api/tests/Unit/GameSelection/BackfillGameLocationsTest.php`
- `api/tests/Unit/GameSelection/BackfillGameOptionTypesTest.php`
- `api/tests/Unit/GameSelection/AdminGameLibraryDefaultYamlTest.php`
- `CHANGELOG.md`
