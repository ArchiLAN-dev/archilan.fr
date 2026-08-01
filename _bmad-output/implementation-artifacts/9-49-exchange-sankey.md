# Story 9.49 - Le graphe des échanges devient un diagramme de flux lisible

## Status

Done

## Story

**En tant que** joueur consultant le récap d'une partie terminée,
**je veux** voir d'un coup d'oeil qui a envoyé combien d'objets à qui,
**afin de** comprendre la dynamique de la partie sans avoir à survoler quoi que ce soit.

## Contexte

La section "Qui a envoyé quoi à qui" était rendue par `ExchangeGraph`, un graphe à force dirigée
dessiné à la main sur `<canvas>`. Un layout à force dirigée est fait pour révéler la structure d'un
réseau dense, à partir d'une quinzaine de noeuds. Une partie ArchiLAN compte 2 à 6 slots. À cette
taille la simulation n'a rien à révéler, et le rendu observé sur une partie réelle à 2 slots était :

- deux ronds de couleur quasi identique portant **le même libellé**, parce que le composant
  préférait le nom du joueur au nom du slot - or les deux slots appartenaient à la même personne,
  ce qui est le cas normal en solo et en duo ;
- les deux sens d'échange (54 objets dans un sens, 42 dans l'autre) tracés en lignes droites de
  centre à centre, donc **superposés et lus comme un seul trait** ;
- **aucun compteur affiché**, le survol ne donnant que des totaux par joueur ;
- les objets qu'un joueur trouve pour lui-même (67 et 28 ici) **totalement absents** de la vue ;
- 26rem de canvas pour deux ronds au centre.

Autrement dit, la question posée par le titre de la section n'avait pas de réponse à l'écran.

## Acceptance Criteria

**AC1 - Un slot est identifiable.** Les noeuds portent le nom de slot AP, unique dans une partie,
et non le nom du joueur. Partout ailleurs sur la page (cartes de superlatifs), un nom de joueur qui
désigne plusieurs slots est désambiguïsé par son nom de slot.

**AC2 - Les deux sens sont distincts et chiffrés.** Chaque flux orienté a son propre ruban et son
propre compteur visible sans interaction. Deux flux de sens opposés ne peuvent pas se superposer.

**AC3 - Les objets locaux sont visibles.** Les objets qu'un slot a trouvés pour lui-même
apparaissent comme un flux, avec leur compteur.

**AC4 - Une identité visuelle unique.** Un slot garde la même couleur entre le diagramme des
échanges et les courbes de la timeline, via la palette catégorielle existante.

**AC5 - Pas de nouvelle dépendance.** Le rendu s'appuie sur `recharts`, déjà dans le bundle.

**AC6 - Accessibilité.** Le tableau `sr-only` miroir est conservé et couvre aussi les objets locaux.

**AC7 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: `ExchangeSankey` dans `features/recap`, construit sur `recharts.Sankey` (AC2, AC3, AC5).
- [x] Task 2: renderers de noeud et de lien maison - couleur par slot, compteur sur le ruban (AC2, AC4).
- [x] Task 3: `slotColorsByName` exporté depuis `build-checks-series` comme source unique des
      couleurs de slot, partagée avec la timeline (AC4).
- [x] Task 4: désambiguïsation des libellés de slots dans `session-recap-page` (AC1).
- [x] Task 5: suppression de `exchange-graph.tsx` (AC2).
- [x] Task 6: gates (AC7).

## Dev Notes

**Pourquoi un Sankey pour un graphe cyclique.** Le graphe des échanges est cyclique : A envoie à B
*et* B envoie à A. Un layout Sankey exige un graphe acyclique - il calcule la profondeur de chaque
noeud par parcours. La parade est de dédoubler chaque slot en un noeud expéditeur (colonne de
gauche) et un noeud destinataire (colonne de droite) : le graphe devient biparti, donc acyclique.
Ce détour technique se lit exactement comme la question posée par la section, et il fait apparaître
gratuitement les objets locaux comme un ruban qui traverse tout droit.

Indexation : le slot `i` occupe les noeuds `2i` (expéditeur) et `2i + 1` (destinataire).

**Placement des compteurs.** Un compteur au milieu du ruban reproduisait exactement le défaut de
l'ancien graphe : deux rubans de sens opposés se croisent au centre, donc les deux étiquettes se
superposaient. Le compteur est donc placé à `t = 0.18` sur la cubique que suit le ruban (fonction
`pointOnCurve`, même courbe que celle dessinée), soit près de son propre expéditeur - deux rubans
quittant des hauteurs différentes, les étiquettes sont séparées par construction. Halo
`paintOrder="stroke"` en couleur de surface pour rester lisible au-dessus de n'importe quel ruban.

**Typage.** Les renderers reçoivent des payloads faiblement typés par recharts ; ils sont lus via
`readString` / `readNumber` / `readObject` plutôt qu'avec un `as` (AC-TS2/AC-TS3).

**Alternatives écartées.** `@nivo/chord` (le diagramme canonique pour une matrice d'échanges, mais
toute une pile d3 en plus et une seconde stack de graphes à maintenir à côté de recharts),
`d3-chord` seul (plus léger mais tout le rendu à écrire), `@xyflow/react` (machinerie d'éditeur de
diagrammes pour un rendu statique), et les libs de graphes denses type cytoscape / sigma.js, qui
auraient reproduit l'erreur d'origine : un outil de réseau dense appliqué à un graphe de 2 noeuds.
