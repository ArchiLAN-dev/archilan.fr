# Story 28.13 - Déclarer qu'on possède un jeu, sans Steam

## Status

Done

## Story

**En tant que** joueur,
**je veux** indiquer moi-même que je possède un jeu,
**afin de** pouvoir filtrer sur « mes jeux » même pour ceux que Steam ne connaîtra jamais.

## Contexte

Le filtre « Mes jeux » repose entièrement sur le couplage Steam, qui ne peut reconnaître qu'un jeu
portant un `steamAppId`. Or une grande partie du catalogue n'en a pas : un titre GameCube, SNES ou
Nintendo 64 ne matchera jamais une bibliothèque Steam, quel qu'en soit le nombre couplé. Ces jeux
sont aujourd'hui **structurellement impossibles** à marquer comme possédés.

Un joueur peut aussi posséder un jeu ailleurs que sur Steam - GOG, Epic, une cartouche - sans que
le couplage puisse le savoir.

## Acceptance Criteria

**AC1 - Marquer et démarquer un jeu** depuis sa page, y compris pour un jeu sans `steamAppId`.

**AC2 - Stocké côté ArchiLAN uniquement**, rattaché au compte. Aucune synchronisation vers Steam,
aucune écriture par le couplage : recoupler Steam ne peut pas effacer un marquage manuel, et
démarquer à la main ne combat pas le couplage.

**AC3 - Le filtre « Mes jeux » unit les deux sources.** Un jeu compte comme possédé s'il vient du
couplage Steam **ou** de la liste ArchiLAN.

**AC4 - Le filtre existe même sans Steam.** L'option « Mes jeux » du catalogue apparaît dès qu'une
des deux sources contient quelque chose, plus seulement quand une bibliothèque est couplée.

**AC5 - Connexion requise.** Contrairement au couplage Steam, qui fonctionne anonymement via le
`localStorage`, cette liste appartient à un compte - c'est ce qui la fait survivre à un changement
de navigateur. Sans session, rien n'est proposé.

**AC6 - Idempotence.** Marquer deux fois, ou démarquer ce qui ne l'était pas, n'est pas une erreur.

**AC7 - Le stockage est indexé par liste, pas par « possession ».** La table `user_game_list` porte
un `kind` dans sa clé primaire (`user_id`, `game_id`, `kind`), et l'API s'adresse à une liste
nommée : `GET /api/v1/me/game-lists/{kind}`, `PUT|DELETE /api/v1/me/game-lists/{kind}/{gameId}`.
Une seule valeur existe aujourd'hui, `owned`. Un `kind` inconnu répond 404, jamais une liste vide.

**AC8 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: enum `GameListKind`, entité `GameListEntry` (clé composite joueur + jeu + liste),
      dépôt, migration (AC1, AC2, AC6, AC7).
- [x] Task 2: service `UserGameLists` - lister, ajouter, retirer, paramétré par liste (AC1, AC6).
- [x] Task 3: `GET/PUT/DELETE /api/v1/me/game-lists/{kind}[/{gameId}]`, authentification requise,
      404 sur liste inconnue (AC5, AC7).
- [x] Task 4: hook `useGameList(kind)` partagé, module d'API front (AC3).
- [x] Task 5: union des deux sources dans `isOwned` et le filtre du catalogue (AC3, AC4).
- [x] Task 6: interrupteur sur la page d'un jeu, affiché même sans `steamAppId` (AC1).
- [x] Task 7: tests API (8 cas) et tests unitaires de l'union des sources (AC2, AC3, AC6, AC7).
- [x] Task 8: gates (AC8).

## Dev Notes

**Le triplet (joueur, jeu, liste) est la clé primaire.** L'idempotence est donc garantie par le
stockage, pas par une vérification dans le service - il n'existe aucun chemin qui crée un doublon.

**Pourquoi une liste nommée dès la première.** « Je possède » n'est pas la seule liste qu'un joueur
voudra tenir devant un catalogue, et la suivante aurait la même forme exactement : quatre colonnes,
trois opérations, une clé composite. Un `kind` dans la clé coûte une colonne ici et évite la
duplication d'une entité, d'un dépôt, d'un service, d'un contrôleur, d'un module d'API et d'un hook
à chaque liste ajoutée. Ce qui est mutualisé est le stockage, pas la sémantique : `isOwned` ne lit
que la liste `owned`, et une liste future aura sa propre règle dans sa propre surface.

**Les deux sources sont unies à la lecture, jamais fusionnées en base.** C'est ce qui rend AC2 vrai
sans code défensif : le couplage Steam n'écrit rien, la liste manuelle n'écrit rien côté Steam, et
`isOwned` fait l'union au moment de l'affichage. Toute tentative de « synchroniser » les deux
recréerait le risque qu'un recouplage écrase un marquage.

**Le badge devient un interrupteur, sauf si Steam a déjà répondu.** Quand la bibliothèque couplée
contient le jeu, on affiche le badge sans contrôle : cette réponse-là appartient à Steam, la défaire
n'aurait pas de sens. L'interrupteur n'apparaît que pour ce que le joueur peut réellement décider.

**Hors périmètre.** Marquer un jeu directement depuis une carte du catalogue (il faudrait un contrôle
sur chaque vignette, à évaluer sur l'usage réel) et la page de sélection de jeux d'une run, qui a son
propre filtre « mes jeux » et gagnera l'union dans une story dédiée.
