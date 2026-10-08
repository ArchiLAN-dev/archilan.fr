# Story 30.53: Où j'en suis d'un succès

**Status:** ready-for-dev
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

## Questions ouvertes pour Jean

- **Qui voit l'avancement d'un autre ?** Par défaut proposé : tout le monde voit la modale d'un succès, mais
  l'avancement chiffré n'est montré **qu'au membre lui-même** (sur le profil d'un autre, seulement « à obtenir »).
  Les chiffres révèlent son activité (checks, items, quêtes), alors que le profil public n'en montre que les totaux.
- Un succès obtenu manuellement par un admin (story 30.34) : afficher « Attribué par l'équipe » ?

## Tasks / Subtasks

- [ ] **Task 1** (AC 2, 3) - Domaine : une évaluation détaillée de l'arbre (`AchievementRuleGroup` / conditions)
  qui renvoie, par nœud, la valeur courante, la cible et si c'est rempli, sans changer `matches()` ; tests unitaires.
- [ ] **Task 2** (AC 3-6) - Application : `AchievementProgressQuery` (profil par slug, définition par clé, secret,
  inactif, obtenu, `MetricBagBuilder::build`) ; contrôleur et limite de débit ; tests fonctionnels.
- [ ] **Task 3** (AC 1, 2) - Front : `achievement-progress-api.ts` (garde de type), modale (`Dialog`) ouverte au clic
  sur `AchievementCard`, rendu de l'arbre (réutiliser les libellés de `achievement-rules.ts`) ; tests.
- [ ] **Task 4** (AC 7) - Gates.

## Dépendances

- Story 30.52 (collections) : la modale affiche la collection et applique la règle du secret.
