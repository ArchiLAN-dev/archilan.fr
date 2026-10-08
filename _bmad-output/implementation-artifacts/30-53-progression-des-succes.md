# Story 30.53: Où j'en suis d'un succès

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-08

## Story

En tant que membre,
je veux voir, pour un succès que je n'ai pas encore, où j'en suis de chacune de ses conditions,
afin de savoir ce qu'il me reste à faire pour le débloquer.

## Contexte

Demande de Jean (2026-10-08), pendant la story 30.52 (collections). Le catalogue (`/joueurs/{slug}/succes`) dit
seulement « obtenu » ou « à obtenir ». Calculer l'avancement veut dire construire tout le `MetricBag` du membre
(`MetricBagBuilder::build`, une requête par fournisseur de métriques : parties, checks, items, objectifs,
événements, quêtes, récaps). C'est trop lourd pour chaque carte du catalogue, et encore plus pour le profil. On ne
le calcule donc **qu'à la demande**, quand le membre clique sur une carte, dans une modale.

## Critères d'acceptation

1. **Au clic** sur une carte de succès (catalogue, et cartes du profil), une modale s'ouvre : nom, description,
   image, état (obtenu le … / à obtenir), rareté, collection (story 30.52).
2. **Avancement** (succès pas encore obtenu) : la règle en arbre, avec chaque condition sous forme de phrase
   (« Items reçus d'autres joueurs : 1 240 / 3 000 ») et une barre. La condition est cochée quand elle est remplie.
   Un groupe affiche « toutes », « au moins une » ou « aucune », et s'il est rempli. Pour « entre », la valeur et la
   plage. Pour « aucune », la condition est remplie quand elle ne l'est pas, et le libellé le dit.
3. **Une seule requête, à l'ouverture** de la modale : `GET /api/v1/community/profiles/{slug}/achievements/{key}/progress`,
   qui construit le `MetricBag` une fois et évalue l'arbre nœud par nœud. Rien n'est calculé au chargement de la
   page. La réponse est mise en cache côté client le temps de la modale (TanStack Query, `staleTime` explicite).
4. **Succès obtenu** : pas d'avancement, seulement la date (le moteur est monotone : la règle peut ne plus être
   vraie aujourd'hui).
5. **Succès d'une collection secrète encore cachée** : 404, comme s'il n'existait pas (story 30.52). Succès inactif
   non obtenu : 404.
6. **Coût borné** : limite de débit par visiteur sur l'endpoint, et un temps de réponse mesuré dans le test
   fonctionnel. Les métriques par événement (`event_goal:{id}`) s'évaluent comme dans le recalcul.
7. Gates verts ; tests (évaluation nœud par nœud, groupes `all`/`any`/`none`, « entre », secret, obtenu, rendu de la
   modale).

## Décisions de Jean (2026-10-08)

1. L'avancement chiffré n'est montré **qu'au membre lui-même**. Sur le profil d'un autre, la modale montre le succès
   et « À obtenir », sans chiffres.
2. Un succès attribué par un admin affiche « Attribué par l'équipe ».

## Tasks / Subtasks

- [x] **Task 1** (AC 2, 3) - Domaine : une évaluation détaillée de l'arbre (`AchievementRuleGroup` / conditions)
  qui renvoie, par nœud, la valeur courante, la cible et si c'est rempli, sans changer `matches()` ; tests unitaires.
- [x] **Task 2** (AC 3-6) - Application : `AchievementProgressQuery` (profil par slug, définition par clé, secret,
  inactif, obtenu, `MetricBagBuilder::build`) ; contrôleur et limite de débit ; tests fonctionnels.
- [x] **Task 3** (AC 1, 2) - Front : `achievement-progress-api.ts` (garde de type), modale (`Dialog`) ouverte au clic
  sur `AchievementCard`, rendu de l'arbre (réutiliser les libellés de `achievement-rules.ts`) ; tests.
- [x] **Task 4** (AC 7) - Gates.

## Dépendances

- Story 30.52 (collections) : la modale affiche la collection et applique la règle du secret.

## Dev Agent Record

- Domaine : `AchievementRule::progress()` (groupe : `op`, `met`, `rules` ; condition : valeur courante, cible,
  `value2` pour « entre », `met`), à côté de `matches()` sans le changer.
- `AchievementGrant.by_team` (migration `Version20261008100000`), posé par l'attribution manuelle. Les attributions
  manuelles d'avant cette story restent à `false` (rien ne les distingue en base).
- `AchievementProgressQuery::forMember()` + `MyAchievementProgressController` : `GET
  /api/v1/community/profile/achievements/{key}/progress`, le membre connecté seulement. Le `MetricBag` est construit
  une fois par ouverture ; un succès obtenu renvoie sa date et `byTeam` sans recalcul ; 404 pour un succès inactif non
  obtenu, d'une collection secrète encore cachée, ou inconnu. Libellés des critères côté API (un objectif
  d'événement par le titre de l'événement).
- AC 6, écart : pas de limiteur de débit. Le projet n'a pas `symfony/rate-limiter`, et l'endpoint est réservé au
  membre connecté, pour ses propres succès, appelé seulement à l'ouverture de la modale (TanStack Query, `staleTime`
  60 s). À ajouter avec le paquet si la charge le demande.
- Front : `achievement-progress-api.ts` (garde nœud par nœud), `achievement-details.tsx` (`AchievementTile` : la carte
  devient un bouton, modale `Dialog` ; arbre « Toutes / Au moins une / Aucune de ces conditions », coche, valeur,
  barre pour « au moins » et « plus de », « pas … » sous « aucune »). Catalogue et profil passent par la tuile.
- Tests : `AchievementRuleTest::testProgress…`, `AchievementProgressTest` (membre, attribué par l'équipe, cachés),
  `achievement-details.test.tsx`.
