# Story 41.32: Essayer un cosmétique sur tout son profil

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-08

## Story

En tant que membre,
je veux essayer un cosmétique de la boutique sur l'en-tête complet de mon profil (bannière, cadre, pseudo, titre),
afin de juger comment il va avec ce que je porte déjà avant de l'acheter.

## Contexte

Retour de Jean (2026-10-08). « Essayer » (story 41.12) montre un cadre ou une bannière sur la photo et la bannière
du membre, mais un titre ou une couleur de pseudo s'essaie sur un avatar seul : ni bannière, ni cadre, ni titre
porté. On ne voit donc pas l'ensemble.

## Critères d'acceptation

1. **Un seul aperçu, quelle que soit la sorte** : l'en-tête du profil du membre (sa bannière, sa photo avec son cadre,
   son pseudo avec son style, son titre), où seul l'objet essayé remplace ce qu'il porte.
2. **Avant / Après** : une bascule montre l'en-tête tel qu'il est aujourd'hui, puis avec l'objet.
3. **Couleur de pseudo et rareté** : si le style de rareté du membre (Légendaire, Épique) passe avant les couleurs sur
   son profil, l'aperçu montre quand même la couleur, avec une phrase qui le dit et indique où le désactiver.
4. **Visiteur** : un profil neutre (pas de bannière perso, pas de cadre, « Toi »).
5. Rien n'est enregistré.
6. Gates verts ; tests (objet remplacé selon la sorte, avant / après, note de rareté).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 4, 5) - `ShopShopper` porte aussi le style du pseudo, la rareté qui l'emporte et le titre ;
  `ProfileTryOn` (bannière, cadre, pseudo, titre) ; tests.
- [x] **Task 2** (AC 2, 3) - Bascule avant / après, note sur la rareté ; tests.
- [x] **Task 3** (AC 6) - Gates ; vérification en local.

## Dev Agent Record

- `shop-try-on.tsx` : `ShopShopper` (+ `nameStyle`, `rarityStyle`, `title`) et `VISITOR` y déménagent ;
  `currentLook()` / `lookWith()` (l'objet remplace seulement ce qu'il remplace) ; `ProfileHeaderPreview` (bannière,
  photo et cadre à cheval, pseudo `TitledName`, badge de titre) ; `TryOnDialog` avec la bascule « Avant / Avec
  l'objet » et la note quand un pseudo à titre passe avant une couleur.
- `shop-page.tsx` : le style du pseudo (rareté si « Pseudo à titre », sinon couleur) et le titre porté viennent du
  profil du membre ; l'ancien `TryOnDialog` et `FramePreview` ne servent plus ici.
- Vérifié en local (anonyme) : « Essayer » sur une couleur montre l'en-tête complet, pseudo coloré.
