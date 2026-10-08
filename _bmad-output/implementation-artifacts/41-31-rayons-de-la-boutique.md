# Story 41.31: Les rayons de la boutique

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-08

## Story

En tant que membre,
je veux trouver les cosmétiques de la boutique rangés par sorte (cadres, bannières, titres, couleurs de pseudo),
afin d'aller droit à ce que je cherche au lieu de parcourir une grille où tout est mélangé.

## Contexte

Retour de Jean (2026-10-08). L'onglet « Cosmétiques » de `/boutique` (`ShopView`, stories 41.7, 41.12, 41.14) montre
une seule grille où cadres, bannières, titres et couleurs se suivent dans l'ordre de mise en vente. Seul le filtre
« En promo » existe. La boutique grandit, et un titre n'a pas le même aperçu qu'un cadre : les mélanger brouille la
lecture. Plus tard, une « collection » de cosmétiques (un cadre, une bannière et un titre sur un même thème) pourra
être mise en avant en tête ; on prévoit sa place sans la construire.

## Critères d'acceptation

1. **Un rayon par sorte**, dans cet ordre : Cadres, Bannières, Titres, Couleurs de pseudo. Chaque rayon a son titre,
   son nombre d'objets et sa grille. Un rayon sans objet en vente n'apparaît pas.
2. **Navigation** : des pastilles en tête (« Tout », « Cadres 12 », « Bannières 5 »…) filtrent la page sur un rayon.
   « Tout » montre tous les rayons à la suite. Le rayon choisi est dans l'adresse (`/boutique?rayon=cadres`) pour
   qu'on puisse le partager. Une adresse inconnue retombe sur « Tout ».
3. **« En promo »** reste un filtre à part, qui se combine avec le rayon ; quand il est actif, seuls les rayons qui ont
   une promo restent, et leurs compteurs suivent.
4. **Grilles adaptées** : les cadres et les bannières gardent l'aperçu actuel ; les titres et les couleurs, plus
   petits, tiennent sur une grille plus dense (4 colonnes sur grand écran).
5. **Dans un rayon** : les nouveautés et les promos d'abord, puis l'ordre de mise en vente. « Possédé » reste visible
   mais passe à la fin.
6. **Place pour la mise en avant** : un emplacement « À la une » au-dessus des rayons, vide et caché tant qu'aucune
   collection de cosmétiques n'existe (rien à construire côté API dans cette story).
7. Gates verts ; tests (rayons et ordre, filtre de l'adresse, combinaison avec « En promo », rayon vide caché).

## Hors périmètre

- Les collections de cosmétiques elles-mêmes (création admin, prix de lot) : story à part le jour où on en veut.
- L'admin de la boutique (`/admin/boutique`) : inchangé.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 4, 5) - `shop-shelves.ts` : regrouper et trier par rayon ; tests.
- [x] **Task 2** (AC 2, 3, 6) - `ShopView` : pastilles de rayon, `?rayon=` (page serveur + lien), « En promo »
  combiné, emplacement « À la une » ; tests.
- [x] **Task 3** (AC 7) - Gates ; vérification visuelle en local.

## Dev Agent Record

- `shop-shelves.ts` : `SHELVES` (ordre, libellé, grille dense pour titres et couleurs), `shopShelves()` (filtre
  « En promo », tri stable : nouveautés et promos, puis le reste, possédés à la fin ; rayon vide retiré),
  `shelfFromParam()` / `shelfHref()`.
- `ShopView` : pastilles de rayon en liens (`?rayon=`, `scroll={false}`) avec compteurs, « En promo » en bascule à
  droite, un titre par rayon, message et lien « Voir toute la boutique » pour un rayon vide ; la carte d'objet est
  extraite en `renderCard`. La page `/boutique` lit `rayon` et le passe à `CosmeticShop`.
- « À la une » : un emplacement commenté au-dessus des rayons, rien d'affiché tant qu'il n'y a pas de collection de
  cosmétiques.
- Vérifié en local (anonyme) : rayons Cadres et Couleurs, filtre par l'adresse.
