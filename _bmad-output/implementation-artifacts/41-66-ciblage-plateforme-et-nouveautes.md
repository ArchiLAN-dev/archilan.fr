# Story 41.66: Objectifs ciblés sur des plateformes ou les nouveautés du catalogue

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-11-01

## Story

En tant qu'admin,
je veux viser des plateformes ou les jeux récemment mis en ligne,
afin d'écrire des semaines à thème (« Semaine Nintendo ») et de faire jouer les nouveaux apworlds.

## Contexte

Suite de la 41.64. Aujourd'hui un objectif ne vise qu'un jeu ou un événement (41.18). Le catalogue n'a ni genre
ni tag ; la seule classification est la famille de plateforme, résolue par
`PlatformCategory::resolve(override admin, plateformes IGDB)` : le champ `game.platform_families` n'est qu'un
override, nul pour la plupart des jeux. Les familles sont par console (« Super Nintendo », « Nintendo 64 »,
« Switch »...), et le vocabulaire est ouvert (un nom IGDB inconnu reste tel quel).

## Critères d'acceptation

1. **Cible plateformes** : un objectif ciblable peut viser **une ou plusieurs familles** (`scope = platform`) : il
   ne compte que les sessions des jeux dont au moins une famille résolue est dans la liste. « Semaine Nintendo » =
   cocher les familles Nintendo. Un jeu sans famille ne compte jamais.
2. **Cible nouveautés** : un objectif ciblable peut viser les nouveautés (`scope = recent`, 7 à 90 jours) : il ne
   compte que les jeux **mis en ligne** à partir de ce nombre de jours avant le **début de la semaine servie**, y
   compris pendant la semaine (la borne ne bouge pas en cours de semaine). Mis en ligne = date de la première promotion d'un candidat apworld
   (`apworld_candidate`, story 38.6), à défaut `game.created_at` pour les jeux d'avant l'historique.
3. **Hebdos** : comme toute cible (41.18), les hebdos ne comptent pas ; l'objectif l'affiche (« hors hebdos »).
4. **Affichage** : « 3 goals sur un jeu Super Nintendo ou Nintendo 64 » (au-delà de 3 familles : « sur un jeu de
   la sélection », avec la liste au survol), « 1 partie sur un jeu mis en ligne ces 30 derniers jours ».
5. **Validation** : une famille absente du catalogue, une liste vide ou un nombre de jours hors bornes est refusé.
   Les familles proposées sont celles réellement résolues sur les jeux du catalogue.
6. Gates verts ; tests (jeu sans famille, famille venue d'IGDB, override admin, jeu multi-familles, jeu d'avant
   l'historique des candidats, bornes de la fenêtre) et des écrans.

## Notes techniques

- La résolution des familles et de la date de mise en ligne vit dans GameSelection : le Wallet la lit par un port
  de son Application (`QuestGameCatalog` : familles par jeu, date de mise en ligne par jeu), implémenté en
  infrastructure. Le filtre se fait en PHP sur la liste des jeux, puis en SQL par `slot.game_id IN (:ids)`.
- `QuestObjective` : `SCOPE_PLATFORM` (scopeId = liste JSON de familles) et `SCOPE_RECENT` (scopeId = jours) ;
  `ManageQuests::objectives` et `DbalWeeklyQuestsQuery::scope()` les apprennent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 5) - Port `QuestGameCatalog`, domaine et SQL des deux cibles ; tests.
- [x] **Task 2** (AC 3, 4) - Noms des cibles côté membre, admin et Discord ; formulaire ; tests jest.
- [x] **Task 3** (AC 6) - Gates, vérification dans l'app.

## Dev Agent Record

### Notes

- Écart 1 (fenêtre des nouveautés) : un jeu compte s'il a été mis en ligne **à partir de N jours avant le début de
  la semaine**, y compris pendant la semaine. La borne basse est fixe pour la semaine (la cible ne bouge pas),
  mais un jeu mis en ligne le mercredi compte aussi : c'est ce qu'on attend d'une quête « nouveautés ».
- Écart 2 (pas de port `QuestGameCatalog`) : le validateur n'interdit qu'un import de l'Infrastructure ou de la
  Presentation d'un autre contexte. `DbalWeeklyQuestsQuery` lit les jeux et leurs plateformes et les résout avec
  `PlatformCategory::resolve` (Domain de GameSelection), comme `DbalGameCatalogQuery`. `scopeOptions()` donne les
  familles au formulaire et à la validation : un port de plus n'apportait rien.
- Mis en ligne = `MIN(apworld_candidate.decided_at)` des candidats `promoted`, à défaut `game.created_at`.
- `QuestObjective` : `SCOPE_PLATFORM` (familles séparées par `|`, triées et dédoublonnées par `familiesOf`, ce
  que `ManageQuests` applique avant d'enregistrer) et `SCOPE_RECENT` (7 à 90 jours) ; `selfName()` nomme ces
  cibles sans catalogue (« jeu Nintendo 64 ou Super Nintendo »), pour le membre, Discord et l'admin.
- La condition de cible porte maintenant ses paramètres et leurs types (`ScopeCondition`), pour la liste de jeux.
- Formulaire : « Sur des plateformes... » ouvre des cases à cocher, « Sur les nouveautés » propose 7, 14, 30, 60
  ou 90 jours ; une cible plateforme sans famille est refusée avant l'envoi.

### File List

- `api/src/Wallet/Domain/ValueObject/QuestObjective.php`, `api/src/Wallet/Domain/Enum/QuestMetric.php`
- `api/src/Wallet/Application/Command/ManageQuests.php`, `api/src/Wallet/Application/Command/AnnounceQuestsOnDiscord.php`,
  `api/src/Wallet/Application/Query/MyWeeklyQuests.php`, `api/src/Wallet/Application/Query/QuestAdminQuery.php`,
  `api/src/Wallet/Application/Query/WeeklyQuestsQueryInterface.php`, `api/src/Wallet/Application/Support/QuestAnnouncement.php`
- `api/src/Wallet/Infrastructure/Query/DbalWeeklyQuestsQuery.php`
- `api/tests/Functional/QuestPlatformAndRecentScopesTest.php` (nouveau), `api/tests/Functional/AdminQuestTest.php`,
  `api/tests/Unit/Wallet/QuestMetricTest.php`
- `frontend/src/features/wallet/admin-quests-api.ts`, `admin-quests-page.tsx` (+ test)
