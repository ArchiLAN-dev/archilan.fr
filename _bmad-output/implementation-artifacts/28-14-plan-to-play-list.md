# Story 28.14 - Une deuxième liste : « À essayer »

## Status

Done

## Story

**En tant que** joueur,
**je veux** marquer un jeu comme « à essayer », en plus de ceux que je possède,
**afin de** retrouver dans le catalogue ce que j'ai envie de proposer à la prochaine LAN.

## Contexte

La story 28.13 a donné au joueur une liste côté ArchiLAN, rattachée à son compte, pour déclarer
qu'il possède un jeu même quand Steam ne peut pas le savoir. Cette liste répond à « qu'est-ce que
je peux lancer ». Elle ne répond pas à « qu'est-ce que je veux découvrir », qui est pourtant la
question posée devant un catalogue Archipelago : un joueur repère un jeu qu'il ne possède pas
encore, et n'a aujourd'hui aucun endroit où le noter.

28.13 a été livrée avec un stockage déjà nommé par liste (`user_game_list`, `kind` dans la clé
primaire) et une API déjà adressée par liste (`/api/v1/me/game-lists/{kind}`). Cette story n'a donc
ni table ni migration ni route à créer : elle ajoute une valeur d'enum et les surfaces qui la
rendent utile.

## Acceptance Criteria

**AC1 - Marquer et démarquer un jeu « à essayer »** depuis sa page, avec ou sans `steamAppId`, aux
mêmes conditions que la liste « je possède » : connexion requise, idempotent.

**AC2 - Les deux listes sont indépendantes.** Marquer « à essayer » ne retire pas de « mes jeux » et
réciproquement. Un même jeu peut appartenir aux deux : on peut très bien posséder un jeu et n'y
avoir jamais joué.

**AC3 - Aucune migration, aucune route nouvelle.** La liste est une valeur de `GameListKind` ;
`/api/v1/me/game-lists/planned` fonctionne par construction. Si cette story a besoin d'une
migration, c'est que 28.13 a été mal découpée.

**AC4 - Le filtre du catalogue devient exclusif.** L'état `ownedOnly: boolean` cède la place à
`list: "all" | "owned" | "planned"`. Un seul jeton de liste est actif à la fois : choisir
« À essayer » remplace « Mes jeux ». Le libellé est « À essayer ».

**AC5 - L'URL porte le filtre.** Nouveau paramètre `liste=mes-jeux|a-essayer`. L'ancien `mes-jeux=1`
posé par 28.12 reste **lu** (une URL partagée continue de marcher) mais n'est plus **écrit**.

**AC6 - Le filtre « À essayer » n'apparaît que s'il a un sens** : joueur connecté et au moins un jeu
dans la liste. Le filtre « Mes jeux » garde sa règle actuelle (union couplage Steam + liste
manuelle).

**AC7 - Le couplage Steam n'écrit toujours rien.** Il ne peuple ni ne vide « à essayer » : posséder
sur Steam ne dit rien de l'envie d'y jouer. `isOwned` continue de ne lire que la liste `owned`.

**AC8 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: `GameListKind::Planned` (une ligne) ; test API attestant que les deux listes ne se
      touchent pas (AC1, AC2, AC3).
- [x] Task 2: front - `GameListKind` gagne `"planned"` ; le hook `useGameList` est déjà paramétré
      (AC1).
- [x] Task 3: bouton « À essayer » sur la page d'un jeu, indépendant du badge de possession et
      affiché même quand Steam a déjà répondu sur la possession (AC1, AC2).
- [x] Task 4: `list` remplace `ownedOnly` dans `games-filter.ts` et `catalog-url-filters.ts` ;
      `filterAndSortGames` prend les jeux « à essayer » (AC4, AC7).
- [x] Task 5: catalogue - jetons de liste mutuellement exclusifs, apparition conditionnée (AC4,
      AC6).
- [x] Task 6: URL - écriture de `liste=`, lecture de `liste=` **et** de l'ancien `mes-jeux=1`
      (AC5).
- [x] Task 7: tests unitaires front - filtre par liste, exclusivité, compat d'URL (AC4, AC5).
- [x] Task 8: gates (AC8).

## Dev Notes

**Ce que le stockage générique ne doit pas devenir.** Les deux listes partagent un stockage, pas une
sémantique. `isOwned` reste l'union « couplage Steam OU liste `owned` » ; `planned` n'entre jamais
dans cette union. Le jour où une liste demandera une règle propre, elle l'aura dans sa surface, pas
dans le dépôt.

**Filtre exclusif, pas cumulable.** L'intersection « possédé ET à essayer » n'a pas de lecture
évidente, alors que la barre de jetons rendrait deux bascules cumulables visuellement ambiguës. Un
seul jeton de liste actif : le choisir remplace l'autre. C'est le seul endroit de cette barre où un
jeton en remplace un autre au lieu de s'ajouter - assumé, parce que « mes jeux » et « à essayer »
sont deux réponses à la même question, pas deux critères.

**Compat d'URL en lecture seule.** `mes-jeux=1` a été introduit par 28.12 et a pu être partagé. Le
lire coûte une ligne ; l'écrire encore aurait figé deux orthographes du même état.

**Hors périmètre.** Marquer depuis une vignette du catalogue (déjà hors périmètre en 28.13), toute
notion de priorité ou d'ordre dans la liste, et toute visibilité collective (« 3 joueurs veulent
essayer ce jeu »). La page de sélection de jeux d'une run est le prolongement le plus évident de
cette liste - c'est le moment où l'on choisit quoi lancer - mais elle a son propre filtre local et
mérite sa story, comme déjà noté en 28.13.
