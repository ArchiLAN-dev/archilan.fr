# Story 32.19 - Contenu : objets échangés et qualité des envois

## Status

Draft

## Story

**En tant que** joueur consultant le récap,
**je veux** savoir quels objets ont circulé et qui a envoyé du lourd,
**afin de** juger l'entraide sur sa valeur et pas sur son volume.

## Contexte

Le récap dit combien d'objets ont circulé, jamais **lesquels**. Le flux porte pourtant le nom de
chaque objet (75 noms distincts en base) et son classement AP (progression, utile, remplissage,
piège). Deux joueurs peuvent avoir envoyé 50 objets chacun avec des contributions radicalement
différentes : l'un cinquante remplissages, l'autre cinquante déblocages. Aucune vue actuelle ne
distingue les deux.

## Acceptance Criteria

**AC1 - Les objets les plus échangés** sont affichés en barres, avec le nom de l'objet et son
nombre d'occurrences.

**AC2 - La qualité des envois par joueur** est affichée en barres empilées : progression, utile,
remplissage, piège.

**AC3 - Dégradation.** Sans flags exploitables, AC2 n'est pas affiché plutôt que d'être affiché
tout en remplissage (voir story 32.15, même règle).

**AC4 - Identité visuelle.** Les couleurs par joueur restent celles de `slotColorsByName` ; les
couleurs par catégorie d'objet sont un jeu distinct, non catégoriel par joueur, pour éviter la
confusion entre « qui » et « quoi ».

**AC5 - Bornage.** Le classement d'objets est plafonné (top 10 par défaut) et le plafond est
annoncé à l'écran - un top silencieusement tronqué se lit comme un inventaire complet.

**AC6 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [ ] Task 1: fonctions pures `buildTopItems(feed, limit)` et `buildSendQuality(feed)`, testées (AC1, AC2, AC3).
- [ ] Task 2: composants de rendu sur `recharts` (déjà dans le bundle) (AC1, AC2, AC4).
- [ ] Task 3: câblage dans `SessionRecapView` (AC5).
- [ ] Task 4: tests - objets sans nom, partie sans flags, égalités dans le top (AC3, AC5).
- [ ] Task 5: gates (AC6).

## Dev Notes

**Flags AP.** Bit 1 = progression, bit 2 = utile, bit 4 = piège ; `0` = remplissage. Un flag `null`
signifie « bridge trop ancien », pas « remplissage » - c'est toute la raison d'AC3. Le front dispose
déjà de `isProgressionFind` ; prévoir l'équivalent pour les autres bits plutôt que de tester les
bits à la main dans le composant.

**`item.name` peut être `null`.** Les événements sans nom d'objet sont exclus du classement, pas
regroupés sous une étiquette « inconnu » qui truquerait le top.

**Égalités.** Le top doit être déterministe : à nombre égal, trier par nom, sinon deux rendus du
même récap peuvent différer et le test devient instable.

**Ce que cette story ne fait pas.** Elle ne touche ni au diagramme des échanges (story 32.17) ni au
tableau comparatif (story 32.16). Si le tableau affiche déjà une colonne de qualité d'envoi, ne pas
la dupliquer ici - trancher au moment de l'implémentation, en faveur d'un seul endroit.
