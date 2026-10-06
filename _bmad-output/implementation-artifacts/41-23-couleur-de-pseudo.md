# Story 41.23: Couleur de pseudo achetable

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-06

## Story

En tant que membre,
je veux acheter une couleur pour mon pseudo avec mes pelles,
afin que mon nom se reconnaisse partout sur le site.

## Contexte

Deuxième dépense de pelles sans visuel à dessiner (après les titres, 41.22), retenue par Jean (2026-10-06).
Décision prise par Claude et annoncée à Jean : la couleur de **rareté** d'un adhérent ou d'un admin (30.44) passe
avant ; la couleur achetée s'applique à qui n'en a pas, ou à qui a décoché « Pseudo à titre ». Personne ne perd son
achat : décocher la case suffit pour la porter.

## Critères d'acceptation

1. **Palette** (code) : 8 couleurs lisibles sur le fond du site (Émeraude, Azur, Rubis, Ambre, Améthyste,
   Turquoise, Lime, Rose), chacune vendable en boutique (nouveau type `color`, prix choisi par l'admin à la mise en
   vente).
2. **Profil** : dans la personnalisation, le membre choisit une couleur qu'il possède (ou aucune) ; l'API refuse une
   couleur inconnue ou non achetée (une couleur déjà portée n'est pas revérifiée).
3. **Affichage** : partout où le pseudo porte déjà un style (page de profil, cartes de l'annuaire, classement,
   commentaires, parties...), la couleur achetée s'applique quand il n'y a pas de couleur de rareté. Transport :
   `nameStyle` vaut `color-<clé>` dans ce cas.
4. **Boutique** : aperçu et essayage du pseudo du membre dans la couleur.
5. Gates verts ; tests (achat, choix, priorité de la rareté, affichage).

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Palette, profil, `nameStyle`, boutique côté API, migration ; tests.
- [x] **Task 2** (AC 2-4) - Front : rendu des couleurs, personnalisation, boutique ; tests.
- [x] **Task 3** (AC 5) - Gates, vérification dans l'app.

## Dev Agent Record

- API : `NameColor` (enum, palette de 8, `NameColor::nameStyle()` = rareté d'abord puis couleur achetée) ;
  `community_profile.name_color`, `CommunityProfile::wearNameColor()` ; `PUT /api/v1/community/profile` accepte
  `nameColor` (absent = inchangé, vide = retiré, à acheter sinon) ; `NameStyleResolver` et les deux requêtes de cartes
  (`DbalCommunityUserDirectoryQuery`, `DbalLeaderboardQuery`) lisent `cp.name_color` : toutes les surfaces qui
  transmettent `nameStyle` affichent la couleur sans autre changement. Boutique : `ShopItem::TYPE_COLOR`, toute la
  palette vendable. Migration `Version20261006160000`.
- Front : `name-colors.ts` (même palette), `TitledName` teinte le pseudo pour `color-<clé>` (sans titre ni emblème),
  `NameColorField` dans la personnalisation, boutique (aperçu, essayage, mise en vente).
- `composer gates` (2 786) et `pnpm gates` (851) verts ; vérifié dans la personnalisation sur le serveur de dev.
