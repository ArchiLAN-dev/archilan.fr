# Story 30.57: Compteurs de checks et d'items pour les succès

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-11-08

## Story

En tant qu'admin,
je veux définir des compteurs sur des checks précis (locations) et des items précis d'un jeu, et y adosser des succès
avec une quantité,
afin de récompenser des exploits concrets en jeu (« battre Morpha », « 100 Gold Skulltula Tokens », « les six
médaillons ») et pas seulement des goals, pour tous les jeux à venir.

## Contexte

Déclencheur : la sortie du remake d'Ocarina of Time (2026-11-05). Jean veut des succès OoT, mais des succès « goal
sur OoT » seuls sont jugés trop pauvres : il faut viser des checks et des items précis, avec des quantités, et le
mécanisme doit servir à tous les jeux (« on va en avoir de plus en plus »).

Ce qui existe déjà :

- **Moteur de succès** (30.4, 30.16) : arbre de règles `{fact, operator, value, value2?}` évalué contre un
  `MetricBag` construit par les providers taggés `community.achievement_metric_provider`
  (`MetricBagBuilder`). Les opérateurs `>=`, `>`, `=`, `!=`, `<=`, `<`, `between` donnent déjà les quantités : il
  suffit qu'un fait renvoie un nombre. Rien à changer dans le moteur.
- **Fait paramétré** : le précédent est `event_goal:{eventId}` (30.32) : préfixe validé syntaxiquement dans
  `AchievementMetricCatalog::isValidFact()`, existence vérifiée par `AdminAchievementService::validateEventScopes()`,
  libellé résolu par `AchievementProgressQuery::labelled()`, exclu de `MemberStatsLayout::otherFacts()`, sélecteur
  dédié côté front (`admin-achievement-event-scope.ts`, `achievement-rule-editor.tsx:204`), formulation publique
  (`achievement-phrasing.ts:82`). **Suivre exactement ce chemin pour `counter:{id}`.**
- **Le feed** (`session_feed_event`, 32.6) : une ligne `item-received` par check, y compris les items qu'on se
  trouve à soi-même, avec `location_id`/`location_name`/`sender_name`/`sender_game` (le monde qui a fait le check)
  et `item_id`/`item_name`/`receiver_name`/`receiver_game` (le monde qui reçoit). Historique depuis le 2026-07-26.
- **Introspection des apworlds** (4.14, 9.58, 38.20) : `game_introspection` garde pour chaque jeu du catalogue la
  liste statique de ses locations (`location_names`, clés de `location_name_to_id`), de ses items (`item_names`) et de
  ses groupes d'items (`item_groups`, groupe => noms, sans « Everything »), lus via `Game::getLocationNames()`,
  `getItemNames()`, `getItemGroups()`. Le lien avec le feed se fait par `Game.archipelagoGameName`
  (`archipelago_game_name`), qui est le nom de jeu que le feed écrit dans `sender_game` / `receiver_game`. **C'est la
  source des noms proposés à l'admin** (demande de Jean du 2026-11-08 : connaître les checks et items
  automatiquement, sans attendre que quelqu'un les ait joués).
- **Slot vers joueurs** : `DbalSlotPlayerSource` (propriétaire et co-joueurs, events et runs perso).
- **Release / collect** : `DbalFoundItemSource::feed()` + `found()` écartent ce qu'une release ou un collect a
  distribué (30.49, 41.64). Réutiliser tel quel, ne pas réécrire les règles.
- **Hebdos** : une session hebdo écrit son feed comme les autres mais n'a pas de `session_slot` ; le lien membre
  passe par `weekly_entries.external_session_id` / `user_id` (voir `DbalWeeklyQuestsQuery::weeklyChecks()`).
- **Recalcul** : à la fin d'une session et à la construction du récap (`AchievementRecomputeTriggerInterface`), plus
  `RecomputeAllAchievementsMessage` toutes les heures (`Schedule.php:84`). Le recalcul **ne retire jamais** un succès
  (epic 30, décision #12).

## Décisions

1. **Un compteur est une entité admin, nommée et réutilisable**, référencée par le fait `counter:{id}`. Plusieurs
   succès (paliers 10 / 50 / 100) partagent le même compteur.
2. **Deux natures** : `location` (une location checkée par un slot que le membre joue, jeu = `sender_game`) et
   `item` (un item reçu par un slot que le membre joue, jeu = `receiver_game`).
3. **Option « avec un autre membre »** (booléen, faux par défaut) : pour `location`, l'item du check part vers un slot
   que le membre ne joue pas ; pour `item`, l'item vient d'un slot que le membre ne joue pas (règle de 30.49). Une
   hebdo ne compte jamais avec cette option.
4. **Ciblage par nom exact**, pas par id : c'est lisible dans l'admin et plus stable d'une version d'apworld à l'autre.
   Le compteur pointe un **jeu du catalogue** (`gameId`) ; le comptage le relie au feed par son
   `archipelago_game_name`, car les ids et les noms ne sont uniques qu'au sein d'un jeu. Un jeu sans
   `archipelago_game_name` ne peut pas porter de compteur.
4bis. **Les noms viennent de l'introspection de l'apworld**, pas de l'historique : toutes les locations et tous les
   items du jeu sont proposés, même jamais joués. Les **groupes d'items** de l'apworld s'ajoutent en un clic (le
   formulaire les déplie en noms, le compteur ne stocke que des noms). Pour un jeu sans introspection (apworld
   ancien, pas encore réintrospecté), repli sur les noms déjà vus dans le feed pour ce jeu.
5. **Trois modes** :
   - `total` : nombre total sur tout l'historique ;
   - `max_session` : le plus grand nombre dans une même session (hebdo comprise) ;
   - `distinct` : nombre de noms différents de la liste atteints au moins une fois (« les six médaillons » = 6).
6. **Une location ne compte qu'une fois par slot** : dédoublonner sur `(session_id, sender_name, location_id)`. En
   mode `total`, « 5 fois Morpha » veut donc dire cinq parties. Un item compte à chaque copie reçue.
7. **Rétroactif** : tout l'historique du feed compte ; le recalcul horaire attribue les succès sans action de plus.
8. **Pas de suppression d'un compteur utilisé** par un succès (actif ou non) : 409 avec message. On peut le modifier ;
   un succès déjà obtenu reste obtenu (recalcul monotone).
9. **Hors périmètre** : les quêtes hebdo sur compteur (story ultérieure côté Wallet, qui réutilisera les mêmes
   compteurs et le même sélecteur), les items de l'inventaire de départ (absents du feed).

## Critères d'acceptation

1. **Admin des compteurs** : dans `/admin/achievements`, une section « Compteurs » liste les compteurs (libellé, jeu,
   nature, cibles, mode, option, nombre de succès qui l'utilisent) et permet d'en créer, d'en modifier et d'en
   supprimer un (sauf s'il est utilisé, décision 8). Validation : libellé requis (80 car. max), jeu requis, 1 à
   50 noms non vides et uniques, nature et mode dans leurs énumérations.
2. **Sélecteur de noms** : l'admin choisit un jeu du catalogue qui a un `archipelago_game_name`, puis la nature, puis
   tape dans un champ d'autocomplétion. Règles (demande de Jean du 2026-11-08) :
   - **Strictement le jeu choisi** : seuls les noms de l'introspection de ce jeu (`location_names` pour `location`,
     `item_names` / `item_groups` pour `item`), jamais ceux d'un autre jeu, même homonymes. En repli feed, filtre
     sur `sender_game` (location) ou `receiver_game` (item) égal à l'`archipelago_game_name` du jeu. Le champ est
     désactivé tant que le jeu et la nature ne sont pas choisis ; changer de jeu vide la saisie et les suggestions.
   - **Filtré sur ce que l'admin a commencé à écrire** : filtrage côté serveur (paramètre `q`), insensible à la casse
     et aux accents, chaque mot tapé doit apparaître (« water morpha » trouve `Water Temple Morpha Heart`). Tri : nom
     qui commence par la saisie, puis mot qui commence par la saisie, puis le reste, puis alphabétique. 30 résultats
     au plus, appel déclenché à partir de 2 caractères avec un délai de 250 ms (TanStack Query, requête précédente
     abandonnée). Les noms déjà sélectionnés ne sont plus proposés.
   - Sans saisie, rien n'est listé (une liste de 1 000 locations n'aide personne), sauf les groupes d'items pour
     `item`. Pour `item`, les groupes de l'apworld (`item_groups`) sont proposés
   et s'ajoutent dépliés en noms. Sans introspection pour ce jeu, la liste vient des noms déjà vus dans le feed, et
   l'écran le signale (« liste incomplète : apworld non introspecté »). Un nom déjà enregistré mais absent de la
   liste (apworld mis à jour) reste affiché, marqué comme inconnu, et conservé.
3. **Fait `counter:{id}`** : choisissable dans l'éditeur de règles via une entrée « Compteur… » suivie du choix du
   compteur, sur le modèle du fait par événement. Un compteur inexistant est refusé à l'enregistrement du succès
   (`InvalidAchievementRuleException`, message en français).
4. **Comptage `location`** : compte les checks des locations ciblées faits par un slot que le membre joue
   (propriétaire ou co-joueur, events et runs perso via `DbalSlotPlayerSource`, hebdos via `weekly_entries`), sur le
   jeu du compteur, une fois par `(session, slot, location)`, hors release et collect (`DbalFoundItemSource`).
5. **Comptage `item`** : compte les items ciblés reçus par un slot que le membre joue, sur le jeu du compteur, y compris
   ceux qu'il se trouve lui-même, hors release et collect.
6. **Option « avec un autre membre »** : appliquée comme dans la décision 3 ; les hebdos ne comptent pas.
7. **Modes** : `total`, `max_session` et `distinct` donnent les valeurs de la décision 5 ; sans aucune ligne, le fait
   vaut 0.
8. **Coût maîtrisé** : le provider ne calcule que les compteurs référencés par au moins un succès actif, avec une
   requête par compteur au plus (pas une par nom), et rien du tout si aucun succès n'en utilise.
9. **Affichage** : la progression d'un succès (30.53), sa modale (30.54) et sa formulation publique montrent le libellé
   du compteur (« Morpha vaincu : 3 / 5 »). Les faits `counter:` n'apparaissent pas dans la page stats
   (`MemberStatsLayout::otherFacts()`).
10. **Cas OoT vérifiés en test** avec des noms réalistes : `Water Temple Morpha Heart` (location, total),
    `Gold Skulltula Token` (item, total et `max_session`), une liste de six noms en `distinct`.
11. Gates verts (`composer gates`, `pnpm gates`).

## Tasks / Subtasks

- [x] **Task 1 - Domaine** (AC 1, 3)
  - [x] `Community/Domain/Entity/AchievementCounter` (id, label, game, kind, targets `list<string>`, mode,
        withOthersOnly, createdAt/updatedAt via paramètre, pas d'horloge interne), méthodes pures de validation.
  - [x] Enums `AchievementCounterKind` (`location`, `item`) et `AchievementCounterMode` (`total`, `max_session`,
        `distinct`).
  - [x] `AchievementCounterRepositoryInterface` (Domain/Repository), implémentation Doctrine + migration
        (`achievement_counter`, `targets` en JSON).
  - [x] `AchievementMetricCatalog::COUNTER_PREFIX = 'counter:'`, `isCounterFact()`, `counterIdFromFact()`,
        `isValidFact()` accepte le préfixe.
- [x] **Task 2 - Comptage** (AC 4 à 8)
  - [x] `Community/Application/Query/FeedCounterQueryInterface` : `values(string $userId, list<AchievementCounter>): array<string, int>`.
  - [x] La construction SQL du comptage (filtres jeu / nature / noms / option / mode, avec une **période
        optionnelle** `since`/`until`) va dans `Shared/Infrastructure/Dbal/DbalFeedCounterSource`, comme
        `DbalFoundItemSource` : la story 41.72 (quêtes sur compteur) la réutilise depuis Wallet.
  - [x] `Community/Infrastructure/Dbal/DbalFeedCounterQuery` : SQL brut sur `session_feed_event`, avec
        `DbalFoundItemSource` et `DbalSlotPlayerSource` (comme `DbalItemsFromOthersQuery`), plus la branche hebdo par
        `weekly_entries` (comme `DbalWeeklyQuestsQuery::weeklyChecks()`). Noms ciblés en
        `ArrayParameterType::STRING`.
  - [x] Vérifier le plan d'exécution (`EXPLAIN ANALYZE`) sur une base réaliste ; ajouter un index seulement s'il est
        justifié (piste : `(type, sender_game, location_name)` / `(type, receiver_game, item_name)`). Attention aux
        faux positifs de `migrations:diff` (voir mémoire doctrine diff).
  - [x] `FeedCounterMetricProvider` (tag `community.achievement_metric_provider`) : charge les compteurs référencés
        par les succès actifs, renvoie `counter:{id}` => valeur.
- [x] **Task 3 - Admin API** (AC 1, 2, 3)
  - [x] Service applicatif + contrôleur `/api/v1/admin/community/achievement-counters` (GET liste, POST, PATCH
        `{id}`, DELETE `{id}`), `/achievement-counters/games` (jeux du catalogue avec `archipelago_game_name`, et s'ils ont une
        introspection) et `/achievement-counters/names?gameId=&kind=&q=` (noms + groupes pour `item`, source
        `introspection` ou `feed`). Mêmes protections que `AdminAchievementController`.
  - [x] Community ne dépend pas de GameSelection : lire `game` / `game_introspection` par une interface de requête
        Community (Application) implémentée en DBAL (Infrastructure), comme les autres lectures inter-contextes.
        Charger un seul jeu à la fois : les noms de tous les jeux ensemble ont déjà fait tomber PHP en mémoire
        (38.20).
  - [x] `AdminAchievementService` : valider l'existence des compteurs référencés (sur le modèle de
        `validateEventScopes()`).
  - [x] `AchievementProgressQuery::labelled()` : libellé du compteur. `MemberStatsLayout::otherFacts()` : exclure le
        préfixe.
- [x] **Task 4 - Front admin** (AC 1, 2, 3)
  - [x] Section « Compteurs » de `admin-achievements-dashboard.tsx` : liste + formulaire (jeu, nature, recherche de
        noms en multi-sélection, mode, option), client dans `admin-achievements-api.ts` (TanStack Query).
  - [x] `achievement-rule-editor.tsx` : entrée « Compteur… » + sélecteur, sur le modèle de
        `admin-achievement-event-scope.ts`.
- [x] **Task 5 - Front public** (AC 9) : `achievement-phrasing.ts` gère `counter:` avec le libellé renvoyé par l'API.
- [x] **Task 6 - Tests** (AC 10, 11)
  - [x] Unitaires domaine : validation du compteur, préfixe du catalogue.
  - [x] Fonctionnel `FeedCounterTest` (sur le modèle de `api/tests/Functional/ItemsFromOthersTest.php`) : location
        dédoublonnée par slot, co-joueur, run perso, hebdo, release et collect exclus, option « avec un autre
        membre », trois modes, mauvais jeu ignoré, valeur 0.
  - [x] Fonctionnel admin : CRUD, 409 sur suppression d'un compteur utilisé, règle avec compteur inconnu refusée,
        listes de jeux et de noms.
  - [x] Fonctionnel du sélecteur : deux jeux avec un nom en commun et des noms proches, la recherche ne renvoie que
        ceux du jeu demandé ; multi-mots, casse, accents, ordre de tri, limite de 30, repli feed filtré sur le jeu.
  - [x] Jest : éditeur de règles (fait compteur), formulaire de compteur (champ désactivé sans jeu, vidé au
        changement de jeu, noms sélectionnés exclus), formulation.
  - [x] `./scripts/gates.sh` vert.

## Dev Notes

- **Couches** : le Domain n'importe rien du projet ; la requête est une interface dans Application et du DBAL dans
  Infrastructure (pas de `Connection` en Application). Community ne dépend pas de Sessions/WeeklyRuns : lire leurs
  tables en SQL, comme les requêtes existantes.
- **Pièges connus** :
  - Les ids AP ne sont uniques qu'au sein d'un jeu : toujours filtrer sur le jeu (`sender_game` pour une location,
    `receiver_game` pour un item).
  - Type de ligne : utiliser la constante `DbalFoundItemSource::ITEM_TYPE` (`item-received`), pas une chaîne écrite à
    la main (bug de 9.48 rappelé dans `SessionFeedEvent`).
  - Une location n'existe que si les options du slot l'activent (Skulltulas, pêche...) : c'est à documenter dans
    l'aide du formulaire, pas à corriger.
  - Un même nom peut être reçu dans un slot hebdo et un slot de partie : les deux comptent (sauf avec l'option).
- **Noms OoT** : le sélecteur les donnera depuis l'introspection ; ceux cités dans la story sont à confirmer
  à l'écran : `Water Temple Morpha Heart`,
  `Kak 50 Gold Skulltula Reward`, `LH Sun`, `Market 10 Big Poes`, `Song from Saria`, item `Gold Skulltula Token`.
- **Pas d'em-dash** dans le code, les messages et les commits.

### References

- `api/src/Community/Domain/AchievementMetricCatalog.php` (préfixes, `isValidFact`)
- `api/src/Community/Application/Service/AdminAchievementService.php:290` (`validateEventScopes`)
- `api/src/Community/Application/Query/AchievementProgressQuery.php:90` (`labelled`)
- `api/src/Community/Application/Support/MemberStatsLayout.php:70`
- `api/src/Community/Infrastructure/Dbal/DbalItemsFromOthersQuery.php`
- `api/src/Shared/Infrastructure/Dbal/DbalFoundItemSource.php`, `DbalSlotPlayerSource.php`, `DbalSlotCheckSource.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php:384` (`weeklyChecks`)
- `api/src/Sessions/Domain/Entity/SessionFeedEvent.php`
- `frontend/src/features/admin/achievement-rule-editor.tsx`, `admin-achievement-event-scope.ts`,
  `admin-achievements-dashboard.tsx`, `frontend/src/features/community/achievement-phrasing.ts`
- Stories 30.16, 30.32, 30.49, 30.53, 30.54, 32.6, 41.19

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (claude-opus-5-5)

### Completion Notes List

- Comptage dans `Shared/Infrastructure/Dbal/DbalFeedCounterSource` (période optionnelle) pour la 41.72. Le feed n'est
  restreint qu'aux sessions qui contiennent une ligne visée : filtrer par nom dans la sous-requête de
  `DbalFoundItemSource::feed()` casserait le calcul des rafales (release non datée vue comme de vrais checks).
- `DbalFoundItemSource::feed()` expose en plus `location_name`, `item_name`, `sender_game`, `receiver_game`.
- Hebdo : pas de slot de session, donc pas de dates de release/collect ; ce qui arrive à partir du goal de la
  tentative ne compte pas.
- Le sélecteur renvoie aussi `unknown` (noms choisis absents de la liste de l'apworld), affichés en ambre.
- Pas d'index ajouté : le volume de feed par session reste faible et la restriction par session s'appuie sur
  `idx_feed_event_session_time`. A revoir avec un `EXPLAIN ANALYZE` en prod si la tâche horaire ralentit.
- Migration `Version20261108120000`.

### File List

- api/migrations/Version20261108120000.php
- api/config/services.yaml
- api/src/Community/Domain/Entity/AchievementCounter.php
- api/src/Community/Domain/Enum/AchievementCounterKind.php, AchievementCounterMode.php
- api/src/Community/Domain/Repository/AchievementCounterRepositoryInterface.php
- api/src/Community/Domain/AchievementMetricCatalog.php
- api/src/Community/Application/Query/FeedCounterQueryInterface.php, CounterGameCatalogueQueryInterface.php, AchievementProgressQuery.php
- api/src/Community/Application/Service/AdminAchievementCounterService.php, AdminAchievementService.php
- api/src/Community/Application/Support/FeedCounterMetricProvider.php, CounterNameSearch.php
- api/src/Community/Infrastructure/Dbal/DbalFeedCounterQuery.php, DbalCounterGameCatalogueQuery.php
- api/src/Community/Infrastructure/Doctrine/DoctrineAchievementCounterRepository.php
- api/src/Community/Presentation/Controller/AdminAchievementCounterController.php
- api/src/Shared/Infrastructure/Dbal/DbalFeedCounterSource.php, DbalFoundItemSource.php
- api/tests/Functional/FeedCounterTest.php, api/tests/Unit/Community/AchievementCounterTest.php, AdminAchievementServiceTest.php
- frontend/src/features/admin/admin-achievement-counters.tsx, admin-achievement-counters-api.ts, admin-achievement-counter-scope.ts, admin-achievement-counters.test.tsx
- frontend/src/features/admin/achievement-rule-editor.tsx, achievement-rules.ts, admin-achievement-event-scope.ts, admin-achievements-api.ts, admin-achievements-dashboard.tsx (+ tests)
- frontend/src/features/community/achievement-phrasing.ts (+ test)
