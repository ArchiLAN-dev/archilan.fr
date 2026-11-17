# Story 54.9 - Habillage ArchiLAN du tracker

**Status:** done
**Epic:** 54 - Trackers PopTracker
**Date:** 2026-11-17
**Dépend de:** 54.5 (écran du tracker), 54.8 (rendu `canvas`, rapport d'erreurs), 54.10 (PR empilée).

## Story

En tant que joueur,
je veux un tracker qui tient dans mon écran, avec les couleurs et les menus du site,
afin de le suivre confortablement à côté de mon jeu, quel que soit le pack.

## Contexte

Retour de Jean après les essais réels (2026-11-17) : l'intégration fonctionne, mais l'écran reproduit fidèlement
la disposition du pack. Certains packs imposent des tailles qui dépassent la largeur de l'écran (scroll horizontal,
vu sur un autre jeu que Majora's Mask), et leurs couleurs de fond ne vont pas avec le site.

## Décisions (Jean, 2026-11-17)

1. **Disposition ArchiLAN** : le site ne reproduit plus l'arbre `dock`/`container`/`array` du pack. Il en extrait
   le contenu et le range lui-même :
   - les **cartes** (nœuds `map`) au centre, une carte à la fois, avec des onglets pour passer de l'une à l'autre
     (nom de la carte du pack) ; zoom et déplacement comme aujourd'hui ;
   - les **items** (`itemgrid`, `item`, `canvas`, `layout` qui en contiennent) dans un panneau latéral repliable,
     en sections qui gardent les titres du pack (`group` avec `header`, titre d'onglet de `tabbed`), dans l'ordre
     du pack ;
   - un pack sans carte (variante « Items only ») : les items prennent toute la largeur ;
   - sur téléphone et tablette en portrait : deux onglets, Items et Cartes.
   - Si l'extraction ne trouve ni carte ni item (structure inconnue), repli sur le rendu actuel, contenu dans la
     largeur.
2. **Jamais de scroll horizontal de la page.** Toute largeur imposée par le pack (`width`, `max_width`, taille des
   items, `canvas`) est bornée par la place disponible : une grille d'items passe à la ligne, un `canvas` ou une
   carte trop large est réduit à l'échelle.
3. **Couleurs du site, toujours** : `background`, couleurs de texte et marges décoratives du pack sont ignorées ;
   fonds, bordures et textes prennent les jetons du site. Les images du pack (items, cartes, pastilles) restent.
   Les couleurs d'état (accessible, séquence break...) restent celles de la légende du site.
4. **Barre d'outils compacte**, sur une ligne : variante, connexion Archipelago, menu « Options » (séquences breaks,
   masquer ce qui est fait, remettre à zéro), bouton plein écran. Sur téléphone, elle passe sur deux lignes au plus.
5. **Erreurs du pack** : une pastille repliée « Le pack a signalé N erreurs », qui s'ouvre sur la liste ; les
   erreurs identiques sont regroupées (« ×3 ») et la liste dit qu'elles viennent du pack, pas du site.
6. **Mode plein écran** : un bouton affiche le tracker seul sur toute la fenêtre (API Fullscreen, en-tête et pied
   du site masqués) ; Échap ou le bouton en sort. Pensé pour un deuxième écran à côté du jeu.

## Critères d'acceptation

1. Sur une fenêtre de 360 px à 2560 px de large, la page du tracker n'a jamais de scroll horizontal, pour le pack
   MM (3 variantes) et pour les packs de la campagne 54.8 qui se chargent.
2. Les cartes s'affichent une à la fois avec des onglets à leur nom ; les items, dans un panneau repliable, par
   sections titrées comme dans le pack. Replier le panneau donne toute la largeur à la carte.
3. Aucun fond ni couleur de texte du pack n'apparaît : thème clair et thème sombre du site, tous deux lisibles.
4. La barre d'outils tient sur une ligne en largeur portable ; « Options » regroupe les réglages et la remise à zéro.
5. Les erreurs du pack sont repliées par défaut et regroupées par message identique.
6. Le mode plein écran masque l'en-tête et le pied du site, et Échap en sort.
7. Rien ne change pour la logique, l'autotracking et l'état sauvegardé (mêmes clés de stockage).

## Tests et gates

8. Jest : extraction cartes/sections sur le pack MM et un pack de la campagne, repli sur structure inconnue,
   regroupement des erreurs, menu Options. Vérification visuelle réelle à 360, 768, 1440 et 2560 px.
9. `pnpm gates` vert.

## Tasks / Subtasks

- [x] **Task 1** - Extraction du layout du pack : cartes (avec noms) et sections d'items (titres, ordre), repli.
- [x] **Task 2** - Nouvelle mise en page : onglets de cartes, panneau d'items repliable, onglets sur téléphone,
      bornage des largeurs (grilles qui passent à la ligne, `canvas` et cartes mis à l'échelle).
- [x] **Task 3** - Couleurs du site à la place de celles du pack.
- [x] **Task 4** - Barre d'outils compacte, menu Options, pastille d'erreurs regroupées, mode plein écran.
- [x] **Task 5** - Tests, vérification visuelle réelle, gates.

## Hors périmètre

- Une vue « liste des checks » (sans carte) : pourrait suivre dans une autre story.
- Le choix de couleurs propres au pack par l'utilisateur (option écartée par Jean : toujours celles du site).

## Dev Agent Record

- `ui/layout-outline.ts` : `outlineLayout` parcourt l'arbre du pack (références `layout` suivies, boucle coupée) ;
  les nœuds `map` donnent les cartes, nommées par les onglets qui y mènent (`path`), chacune une fois ; les blocs
  sans carte qui contiennent des items (`item`, `itemgrid`, `canvas`) donnent les sections, titrées par le `header`
  du groupe ou le titre de l'onglet ; une colonne de groupes titrés donne une section par groupe. Un bloc de texte
  seul n'est pas repris. `groupMessages` regroupe les erreurs identiques.
- `ui/tracker-board.tsx` : cartes une à la fois (onglets jusqu'à 6, liste « Carte » au-delà, chemin complet
  « Dungeons › Main › Woodfall Temple ») ; panneau Objets à gauche, repliable sur grand écran ; onglets Cartes /
  Objets sous 1024 px ; sans carte, les objets prennent toute la largeur ; structure illisible : rendu du pack dans
  un cadre qui ne dépasse pas la largeur (`data-board="pack"`).
- `ui/tracker-layout.tsx` : `background` du pack ignoré ; rangées d'`itemgrid` qui passent à la ligne ; `canvas`
  réduit à la place disponible (`FitWidth`, jamais agrandi).
- `ui/tracker-toolbar.tsx` : menu Options (séquences breaks, masquer ce qui est fait, remise à zéro) et bouton plein
  écran (API Fullscreen sur le bloc du tracker seul ; Échap natif). Erreurs du pack repliées, regroupées « ×N »,
  avec la mention qu'elles viennent du pack.
- Écart : la connexion Archipelago reste sur sa propre ligne sous la barre (sa phrase d'état et son formulaire ont
  besoin de place) ; la barre Variante / Options / plein écran tient sur une ligne.
- Vérification réelle (2026-11-17, pack MM 0.9.5.3) : 3 variantes, aucune largeur de page au-delà de la fenêtre à
  360 px et en bureau ; 5 sections (Items, Masks, Equipment, Dungeon Items, Collectibles) ; 20 cartes dans la liste ;
  fond du site. Plein écran : l'appel vise bien le bloc du tracker sans l'en-tête du site, mais le navigateur piloté
  le refuse (« not granted ») : à confirmer dans un vrai navigateur. Le jeu au scroll horizontal signalé par Jean
  reste à vérifier (seul MM en local).
- Retour de Jean (2026-11-17), ajouté à la story :
  - **Zoom plancher** : on ne dézoome plus au-delà de la carte entière ; atteint, la carte revient centrée
    (`zoomAt` borné par `fitTransform`, molette, pincement et bouton).
  - **Pastilles comme PopTracker** : chaque section encore à faire apporte un état (vert accessible, rouge
    inaccessible, jaune séquence break ou partiel, bleu à inspecter ; gris si tout est fait), et la case est coupée
    selon ses diagonales avec la table `triangleValues` de PopTracker (`PIN_PARTS`, haut / gauche / bas / droite) au
    lieu d'une couleur « en partie accessible ». Couleurs reprises de `StateColors` (MapWidget) : #20ff20, #cf1010,
    #ffff20, #3040ff, #3f3f3f. Légende sans « En partie accessible », avec un exemple de case partagée. Vérifié sur
    MM : 11 lieux en case partagée vert/rouge.
  - **Légende en mots simples** (`ui/tracker-legend.tsx`) : Faisable, Hors logique, À regarder, Pas encore, Fait,
    Plusieurs couleurs ; placée au-dessus de la carte (et non plus dans la barre), pastilles dessinées comme sur la
    carte, et « Que veulent dire les couleurs ? » déplie une phrase par couleur. Mêmes mots dans les pastilles
    (« En partie : faisable, pas encore ») et le détail d'un lieu ; option « Montrer ce qui est faisable hors
    logique ».
  - **Légende en compteurs cliquables** (choix de Jean) : la légende devient les compteurs de la carte affichée
    (« 18 faisables », « 11 en partie », « 73 pas encore »), seules les couleurs présentes apparaissent ; un clic ne
    garde que ces lieux sur la carte (plusieurs à la fois, « Tout afficher » pour revenir, remis à zéro au changement
    de carte) ; le sens de chaque couleur au survol, et le « ? » déplie toutes les explications (`countPins`,
    `pinCategory`).
  - **Carte sur téléphone** : la zone prend les proportions de l'image (au plus 75 % de l'écran) au lieu de 60 % de
    la hauteur d'écran, et le bloc du tracker n'impose plus 70 % de hauteur : plus de bande vide autour d'une carte
    large. Grand écran inchangé.
  - **Correctif v0.68.2, pack Minish Cap (deoxis9001)** : son onglet « Settings », à côté des cartes, était serré
    dans le panneau Objets (deux rangées d'onglets imbriqués qui débordaient, étiquettes 320x64 écrasées en 32x32).
    Un onglet voisin des cartes qui a ses propres onglets ou une rangée plus large que le panneau devient une page de
    la zone centrale (`OutlineMap.page`), ouverte par un bouton à côté de la liste des cartes ; `item_width` /
    `item_height` sont lus (`readItemSize`) ; les onglets d'un pack passent à la ligne ; les sélecteurs Pack /
    Variante ne dépassent plus la largeur de l'écran.
- Tests : `layout-outline.test.ts` (7), disposition ArchiLAN dans `tracker-widgets.test.tsx` (4), erreurs regroupées
  et plein écran dans `tracker-screen.test.tsx`. `pnpm gates` vert (1901 tests).
