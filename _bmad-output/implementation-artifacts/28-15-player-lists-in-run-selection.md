# Story 28.15 - Les listes du joueur dans la sélection de jeux d'une run

## Status

Done

## Story

**En tant que** joueur qui compose sa run,
**je veux** retrouver mes jeux et ma liste « à essayer » au moment de choisir,
**afin de** ne pas avoir à me souvenir de ce que j'avais repéré dans le catalogue.

## Contexte

Deux dettes se rejoignent sur cette page.

28.13 a unifié « mes jeux » (couplage Steam **ou** liste ArchiLAN) dans le catalogue public, mais a
laissé la page de sélection d'une run derrière : elle a son propre filtre local, encore adossé au
seul couplage Steam. Un joueur qui a marqué ses jeux GameCube à la main les voit dans `/jeux` et pas
ici - la même question donne deux réponses selon la page.

28.14 a créé la liste « à essayer », et l'a exposée dans le catalogue seulement. Or le moment où l'on
choisit quoi lancer est exactement celui où l'on veut voir ce qu'on avait envie de découvrir. Une
liste d'envies qu'on ne peut pas consulter au moment de décider est une liste qu'on remplit et qu'on
ne rouvre jamais.

## Acceptance Criteria

**AC1 - Le filtre « Mes jeux » de cette page unit les deux sources**, comme le catalogue : couplage
Steam **ou** liste ArchiLAN. Un jeu sans `steamAppId` marqué à la main y est désormais reconnu.

**AC2 - Un filtre « À essayer »** apparaît à côté, exclusif avec « Mes jeux » : même règle que le
catalogue, un seul jeton de liste actif à la fois.

**AC3 - Les deux marqueurs sur une carte.** « Tu possèdes ce jeu » suit l'union, et un marqueur
« À essayer » s'affiche sur les jeux de cette liste. Les deux peuvent coexister.

**AC4 - Chaque filtre n'apparaît que s'il a un sens** : sa source doit contenir quelque chose. Le
filtre « Récemment joués », lui, ne change pas.

**AC5 - « Récemment joués » reste cumulable** avec un filtre de liste : ce sont deux axes
différents, contrairement aux deux listes entre elles.

**AC6 - Aucune écriture depuis cette page.** Elle lit les listes, elle ne les modifie pas : on y
compose une run, on n'y tient pas son inventaire.

**AC7 - Le câblage de cette page est testé.** La dérivation (prédicat de filtrage, ordre d'affichage,
options offertes) sort du composant dans un module pur, couvert par des tests unitaires. Sans quoi
AC1 à AC5 ne seraient vérifiés par rien.

**AC8 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: `useGameList("owned")` et `useGameList("planned")` sur la page, appelés au niveau des
      autres hooks (avant les retours anticipés) (AC1, AC2).
- [x] Task 2: `ownedOnly: boolean` devient `list: ListFilter`, comme le catalogue (AC2, AC5).
- [x] Task 3: filtrage - union pour `owned`, liste seule pour `planned` (AC1, AC2).
- [x] Task 4: jetons exclusifs entre eux, conditionnés à une source non vide ; réinitialisation
      quand une source se révèle vide, une fois celle-ci résolue (AC2, AC4).
- [x] Task 5: marqueurs de carte - union pour « Tu possèdes ce jeu », ajout de « À essayer »
      (AC3).
- [x] Task 6: extraction de la dérivation dans `run-game-filters.ts` (`filterRunGames`,
      `orderRunGames`, `runFilterOptions`) et recâblage de la page dessus (AC7).
- [x] Task 7: tests unitaires du module - union des sources, étanchéité des deux listes, cumul avec
      « récemment joués » et les catégories, ordre épinglé, options offertes (AC7).
- [x] Task 8: gates (AC8).

## Dev Notes

**Aucune nouvelle API.** La page consomme les hooks livrés par 28.13/28.14. Les requêtes TanStack
sont partagées par clé (`["game-lists", kind]`), donc ouvrir cette page après le catalogue ne
redemande rien au serveur.

**Pourquoi les hooks montent tout en haut.** Le corps de ce composant fait plusieurs retours
anticipés (chargement, run introuvable, accès refusé) avant d'atteindre le bloc de filtrage. Un
`useGameList` appelé près du filtrage violerait les règles des hooks (AC-HK4).

**La réinitialisation attend `settled`.** Ici les filtres ne viennent pas de l'URL, donc le risque
est moindre que dans le catalogue, mais la règle reste la même : une liste vide ne veut dire
« rien dedans » qu'une fois la session résolue et la requête revenue. Avant ça, elle est
indiscernable de « pas encore chargée ».

**Pourquoi extraire plutôt que tester le composant.** Le harnais front n'a pas de
`@testing-library/react` : les trois tests de composant existants passent par
`renderToStaticMarkup`, qui ne peut rien dire d'un composant à hooks, requêtes TanStack et retours
anticipés. Plutôt que d'ajouter une dépendance de test pour une story de câblage, la dérivation sort
dans `run-game-filters.ts` et c'est elle qui est couverte. La page ne garde que l'état et le rendu.

**Ce que les tests ne couvrent pas.** Que le composant appelle bien ces fonctions, avec les bons
arguments. C'est une lecture de revue, pas une assertion - la contrepartie assumée de ne pas monter
la page.

**Hors périmètre.** Marquer ou démarquer depuis cette page (AC6) : on y compose une run. Et le tri -
« à essayer » ne remonte pas les jeux en tête de liste, seul « Récemment joués » a ce privilège,
parce qu'il répond à une question de fraîcheur et pas de préférence.
