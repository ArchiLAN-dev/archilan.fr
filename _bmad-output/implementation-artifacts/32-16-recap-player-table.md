# Story 32.16 - Tableau comparatif des joueurs

## Status

Done

## Story

**En tant que** joueur consultant le récap,
**je veux** comparer les joueurs ligne à ligne sur les mêmes colonnes,
**afin de** situer ma partie par rapport aux autres sans avoir à croiser trois graphes.

## Contexte

Le récap affiche quatre cartes de superlatifs (« Le Parrain », « Le Facteur »…). Chacune donne une
valeur - « 54 objets » - sans point de comparaison : on ne sait pas si 54 est beaucoup, ni ce
qu'ont fait les autres. Les données par joueur existent pourtant toutes (checks, objets reçus,
envoyés, gardés, heure du but) mais ne sont visibles nulle part ensemble.

## Acceptance Criteria

**AC1 - Une ligne par slot**, avec : joueur, nom de slot, jeu, checks trouvés, objets reçus,
objets envoyés, objets gardés, heure du but, temps total.

**AC2 - Le tableau absorbe l'existant.** Les quatre cartes de superlatifs deviennent des badges
posés sur la ligne du joueur concerné, et la **section Podium** - qui liste déjà les mêmes joueurs
avec leur jeu et leur temps - est remplacée par le tableau. Sans cela la page afficherait deux
listes des mêmes joueurs à quelques centaines de pixels d'écart. La section « Chronologie des
objectifs » est conservée : elle raconte l'ordre d'arrivée, pas les totaux.

**AC3 - Identité visuelle cohérente.** La pastille de couleur d'une ligne est celle du slot dans le
diagramme des échanges et dans les courbes (`slotColorsByName`, story 9.49).

**AC4 - Les cas particuliers sont lisibles.** Un slot libéré (`wasReleased`) et un slot invalidé
(`isInvalidated`) sont signalés explicitement et ne sont pas classés comme les autres.

**AC5 - Nom ambigu désambiguïsé.** Quand un joueur détient plusieurs slots, la ligne montre le nom
de slot en plus du nom du joueur (même règle qu'en story 9.49).

**AC6 - Mobile.** Le tableau défile horizontalement plutôt que de s'écraser, ou bascule en cartes.

**AC7 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: fonction pure `buildPlayerRows(recap)` (agrégats + badges), testée (AC1, AC2, AC4).
- [x] Task 2: composant `RecapPlayerTable` (AC1, AC3, AC6).
- [x] Task 3: suppression des cartes de superlatifs de `SessionRecapView` (AC2).
- [x] Task 4: tests - slot invalidé, slot sans but, joueur multi-slots (AC4, AC5).
- [x] Task 5: gates (AC7).

## Dev Notes

**Les agrégats viennent de la projection, pas du flux.** `recap.graph` porte déjà tout :
`edges` donne les envois et réceptions par slot, `localItems` les objets gardés. Les recalculer
depuis `feed` serait plus coûteux et pourrait diverger de ce que montre le diagramme juste au-dessus.
Le flux n'est utilisé que pour les couleurs (`slotColorsByName`).

- objets envoyés = somme des `edges[].count` où `fromSlotId` = slot, **plus** ses `localItems`
- objets reçus = somme des `edges[].count` où `toSlotId` = slot, **plus** ses `localItems`
- objets gardés = son entrée dans `localItems`

**Convention tranchée (vaut aussi pour la story 32.17) :** « envoyés » et « reçus » comptent les
échanges **avec les autres joueurs** et excluent les objets qu'un slot s'est trouvés à lui-même,
qui ont leur propre colonne « gardés ». C'est la sémantique qu'utilisait déjà le superlatif « a
envoyé le plus d'objets aux autres » ; le diagramme de la story 9.49 les incluait au contraire dans
ses totaux de noeud et affichait donc 82 là où la carte de superlatif affichait 54 pour le même
slot. Le diagramme a été aligné sur cette convention, pas l'inverse.

**Tri.** Par heure de but croissante, les slots sans but à la fin. Ne pas trier par nombre d'objets :
le récap n'est pas un classement de productivité, et `completionSeconds` est déjà la métrique de
podium ailleurs dans le produit.

**Badges.** `recap.superlatives` porte `key`, `label`, `slotId`, `value`. Les libellés sont déjà
rédigés côté serveur (références pop-culture, cf. mémoire projet) - ne pas les réécrire côté client.
