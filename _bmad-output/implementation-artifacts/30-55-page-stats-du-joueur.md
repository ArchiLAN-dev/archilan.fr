# Story 30.55: Une page « Stats » sur le profil, avec tout ce qu'on suit d'un joueur

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-27
**Origine :** Jean le 2026-10-27, après les stories 32.25 (morts DeathLink) et 32.26 (temps de jeu) : une page de
stats pour que les joueurs voient tout ce qu'on suit.

## Story

En tant que joueur,
je veux une page qui rassemble toutes mes stats (parties, progression, social, hebdo, recaps, morts, temps de jeu) et
leur détail par jeu,
afin de voir ce que le site sait de mon parcours et de me comparer aux autres.

## Contexte

- Le profil public (`/joueurs/[slug]`, `PlayerProfileQuery`) n'affiche que 4 chiffres : runs, objectifs, checks,
  taux de complétion. L'onglet `/joueurs/[slug]/succes` montre le catalogue des succès.
- Les succès reposent sur une vingtaine de **faits** par membre, calculés par `MetricBagBuilder::build` (un appel par
  fournisseur `community.achievement_metric_provider`), avec un libellé français par fait dans
  `AchievementMetricCatalog::facts()`. C'est déjà « tout ce qu'on suit », sous une forme lisible.
- Ce calcul est lourd (une requête par fournisseur) : la story 30.53 ne le fait qu'à la demande, dans une modale,
  derrière une limite de débit. Mais il tourne déjà **pour chaque membre toutes les heures**
  (`RecomputeAllAchievementsMessage`, `Schedule.php:83`, puis `RecomputeAchievements::recomputeForUser`), ainsi qu'après
  la construction d'un recap et quelques actions. Le résultat est jeté après l'évaluation des règles.
- Données par jeu disponibles : `session_slot` (jeu, checks, objectif, release), `session_slot_play_span` (32.26),
  `session_feed_event` de type `death_link` (32.25) ; lien slot vers membres par `DbalSlotPlayerSource` (propriétaire
  et co-joueurs). Une tentative hebdo mène à son jeu par `weekly_entries.weekly_run_id`, puis la run hebdo et son
  modèle (`weekly_templates.game_id`).
- **Les faits `runs`, `goals`, `checks`, `items` ont des règles de comptage précises**
  (`Identity\Infrastructure\Dbal\DbalPlayerStatsQuery`, la source du profil comme des succès) :
  - sessions `finished` seulement ;
  - une partie privée ne compte que si le joueur y a atteint un objectif (story 17.15) ;
  - checks et items d'un slot libéré sans objectif exclus (`COUNTS`) ;
  - les tentatives hebdo terminées comptent (une partie, un objectif, leurs checks et items, story 18.9).
  Un tableau par jeu qui compterait autrement ne retomberait pas sur les totaux affichés juste au-dessus.
- `RecomputeAchievements::recomputeForUser` ne fait pas de `flush` à lui seul : seul l'enregistrement d'un succès
  obtenu en fait un (`DoctrineAchievementGrantRepository::save`). Sans succès nouveau, rien n'est écrit.
- Le profil n'a pas d'onglets : les liens vers `/joueurs/[slug]/succes` viennent des composants Community
  (`profile-achievements.tsx`, `achievement-collections.tsx`, `community-hub.tsx`).

## Acceptance Criteria

1. **Instantané des faits.** Nouvelle entité `MemberStatsSnapshot` (table `member_stats_snapshot` : `user_id` PK,
   `facts` JSON, `computed_at`), contexte Community. `RecomputeAchievements::recomputeForUser` enregistre le
   `MetricBag` qu'il vient de construire (remplacement complet) par `MemberStatsSnapshotRepositoryInterface::save`,
   qui fait son propre `flush` (le recalcul n'en fait pas quand aucun succès n'est obtenu). Aucun calcul en plus.
   Migration. `MetricBag` gagne un accès en lecture à tous ses faits (`all(): array<string, int>`).
2. **Endpoint public** `GET /api/v1/community/profiles/{slug}/stats`, mêmes règles de visibilité que
   `/profiles/{slug}/achievements` (404 si le profil n'est pas visible). Réponse :
   ```json
   {"displayName": "...", "slug": "...", "computedAt": "ISO|null",
    "groups": [{"key": "games", "label": "Parties", "facts": [{"key": "runs", "label": "Parties jouées", "value": 12, "unit": null}]}],
    "superlatives": [{"key": "most_generous", "label": "...", "value": 2}],
    "games": [{"game": "...", "runs": 3, "goals": 2, "checks": 540, "playedSeconds": 18000, "deaths": 7}]}
   ```
3. **Groupes.** Les faits sont rangés par thème dans un ordre fixe, défini côté API (pas dans le front) :
   - Parties : `runs`, `goals`, `eventsWithGoal`, `distinctGames` ;
   - Progression : `checks`, `items`, `itemsFromOthers` ;
   - Temps de jeu : total, plus longue session, record sur une partie, **en secondes**, lus en direct par
     `PlayTimeStatsQueryInterface` (une requête légère) et non dans l'instantané : les faits `playedHours` et
     `maxPlayedHoursInSession` sont arrondis à l'heure inférieure pour les règles (3 h 59 y vaut 3). Ces trois faits
     ne sont donc pas répétés ailleurs (ni dans « Autres ») ;
   - DeathLink : `deathLinkDeaths`, `deathLinkVictims`, `maxDeathLinkDeathsInSession` ;
   - Social : `distinctCoplayers`, `distinctFriendsPlayedWith`, `maxFinishedWithSamePerson`, `weeklyDuelsWon` ;
   - Hebdo : `questsCompleted`, `questChestStreak` ;
   - Recaps : `superlatives` (total).
   Un fait du catalogue absent de ces listes tombe dans un groupe « Autres » : un fait ajouté plus tard pour un succès
   apparaît sans toucher à la page. Les faits `event_goal:*` ne sont pas affichés (déjà résumés par
   `eventsWithGoal`). Les `superlative:*` vont dans `superlatives`, avec le libellé du catalogue. Un fait absent de
   l'instantané vaut 0.
4. **Sans instantané** (membre jamais recalculé) : `computedAt` vaut `null`, tous les faits à 0. Pas de calcul à la
   volée du `MetricBag` sur l'endpoint public (coût borné : une lecture d'instantané, la requête du temps de jeu et
   celle du détail par jeu ; pas de limite de débit nécessaire).
5. **Détail par jeu.** Nouvelle méthode par jeu à côté de `DbalPlayerStatsQuery::computeForUser` (même classe,
   mêmes règles, mêmes jointures, regroupées par jeu), pour que la somme des lignes retombe sur les faits `runs`,
   `goals` et `checks` :
   - parties, objectifs et checks selon les règles du Contexte (sessions `finished`, garde de l'objectif pour les
     parties privées, `COUNTS`, tentatives hebdo terminées via leur modèle) ;
   - temps connecté (somme des spans des slots du membre pour ce jeu) et morts DeathLink envoyées, sur toutes ses
     sessions, sans ces règles (les hebdos n'en ont pas) ;
   - une ligne par jeu présent dans l'un ou l'autre ; tri par temps connecté, puis parties, puis nom du jeu ;
   - nom du jeu résolu par `game.name`.
   Un test vérifie que la somme des lignes égale `runs`, `goals` et `checks` du même membre.
6. **Page** `/joueurs/[slug]/stats` (Server Component, `generateMetadata` complet) :
   - un lien « Toutes les stats » sous les 4 chiffres du profil (`player-profile-page.tsx`), et des liens croisés
     entre `/joueurs/[slug]/stats` et `/joueurs/[slug]/succes` ;
   - une carte par groupe, chaque fait en chiffre + libellé ; le temps en heures et minutes ;
   - les superlatifs de recap en badges avec leur compte ;
   - le tableau par jeu (défilement horizontal sur mobile), durées par `formatDuration`, « - » pour 0 ;
   - « Mis à jour il y a … » d'après `computedAt` (calculé côté serveur, jamais pendant un rendu client), ou « Pas
     encore calculé » ; les chiffres peuvent avoir jusqu'à une heure de retard sur les 4 du profil, qui sont
     calculés en direct ;
   - une note sous le temps de jeu et DeathLink : comptés depuis le 2026-10-27 (date de déploiement à ajuster),
     temps connecté AFK compris, victimes estimées.
7. **Tests.** API : écriture de l'instantané au recalcul, endpoint (groupes dans l'ordre, groupe « Autres »,
   superlatifs, temps de jeu en secondes, sans instantané, profil introuvable), requête par jeu (slot co-joué, autre
   membre, partie privée sans objectif, slot libéré, tentative hebdo, somme des lignes égale aux faits). Front : type guard, rendu des groupes, du tableau, de l'état « pas encore calculé ». Gates verts.

## Tasks / Subtasks

- [x] API : `MemberStatsSnapshot`, migration, écriture dans `RecomputeAchievements` (AC 1) + tests
- [x] API : détail par jeu dans `DbalPlayerStatsQuery` (AC 5) + tests
- [x] API : requête de la page (groupes, superlatifs, par jeu) et endpoint (AC 2, 3, 4) + tests
- [x] Frontend : API typée, page `/joueurs/[slug]/stats`, onglet (AC 6) + tests
- [x] Gates

## Dev Notes

- Pourquoi un instantané plutôt qu'un cache : le calcul existe déjà, toutes les heures, pour tous les membres ; le
  garder coûte une écriture et rend la page aussi légère que le profil. La fraîcheur (une heure au plus, ou juste
  après un recap) suffit pour des stats cumulées.
- Le groupement vit dans une classe Application de Community (par exemple `MemberStatsLayout`), pas dans
  `AchievementMetricCatalog`, qui reste le catalogue des faits des règles (classe importée par des migrations, voir la
  carve-out d'`api/CLAUDE.md` : ne pas la déplacer).
- Le temps et les morts par jeu reprennent le lien `session_id` + `slot_name` de `DbalDeathLinkStatsQuery` et
  `DbalPlayTimeStatsQuery`, avec la même dette connue sur les alias (32.26, Dev Notes).
- Le détail par jeu vit dans le contexte Identity (là où sont les règles de comptage) et la page dans Community :
  Community lit déjà `PlayerStatsQueryInterface` (`StatsMetricProvider`), même sens de dépendance.
- L'instantané est écrit à chaque recalcul, même inchangé : `computed_at` doit dire quand il a été vérifié. Une ligne
  par membre et par heure, volume négligeable.
- Visibilité : publique, comme le profil et les succès (choix de Jean le 2026-10-27). Une option « stats privées »
  pourra suivre si un membre le demande.
- Pour plus tard (hors périmètre) : graphes dans le temps (activité par mois, temps de jeu par semaine).

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-27.
  - Instantané : `MemberStatsSnapshot` (`member_stats_snapshot`, migration `Version20261025200003`),
    `MemberStatsSnapshotRepositoryInterface::save` (persist + flush), écrit en fin de
    `RecomputeAchievements::recomputeForUser` depuis `MetricBag::all()`.
  - Par jeu : `PlayerGameStatsQueryInterface` (Identity), implémentée par `DbalPlayerStatsQuery::perGameForUser`, qui
    réutilise `sessionRows` (paramètre `$byGame`) et ajoute les hebdos via `weekly_runs` puis `weekly_templates`.
    Temps et morts par jeu : `GameActivityQueryInterface` / `DbalGameActivityQuery` (Community). Écart assumé et
    affiché : une partie qui contient plusieurs jeux du joueur compte une fois dans chacun, donc la somme des
    « parties » par jeu peut dépasser le fait `runs` (objectifs et checks retombent exactement sur les totaux, testé).
  - Page : `MemberStatsQuery` + `MemberStatsLayout` (groupes, « Autres », superlatifs), endpoint
    `GET /api/v1/community/profiles/{slug}/stats` (`MemberStatsController`). Le groupe « Temps de jeu » est inséré
    après « Parties », en secondes, depuis `PlayTimeStatsQueryInterface`.
  - Front : `player-stats-api.ts` (type guards), `player-stats-page.tsx`, route `/joueurs/[playerSlug]/stats`, lien
    « Toutes les stats » sous les chiffres du profil, lien « Voir ses stats » sur le catalogue des succès. Écart :
    date de mise à jour absolue (« Mis à jour le 27 octobre à 14:00 ») plutôt que « il y a … », selon la convention
    du site (`community-hub.tsx`, dérive à l'hydratation).
  - Tests : `MemberStatsTest` (écriture de l'instantané, groupes dans l'ordre, temps en secondes, par jeu avec slot
    libéré, partie privée sans objectif et hebdo, somme égale aux totaux, sans instantané, 404, catalogue sans fait
    orphelin) ; constructions de `RecomputeAchievements` mises à jour dans 3 fichiers de test ;
    `player-stats-page.test.tsx`. `pnpm gates` vert (1277 tests).
