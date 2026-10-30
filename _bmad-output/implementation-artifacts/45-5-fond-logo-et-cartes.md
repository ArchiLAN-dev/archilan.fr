# Story 45.5 - Logos clairs sur fond sombre, cartes partenaires moins écrasées

**Status:** review
**Epic:** 45 - Mettre en avant nos partenaires (`_bmad-output/planning-artifacts/epics/epic-45-partenaires.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-45-story-5-fond-logo-et-cartes` (depuis `develop`, epic 45.1-45.4 mergée)
**Origine:** retour de Jean en test local, premier vrai partenaire (Minimapperz)

## Story

En tant qu'admin,
je veux pouvoir afficher un logo clair sur un fond sombre, et des cartes où le logo a de la place,
afin que chaque partenaire soit lisible et mis en valeur sur l'accueil et partout ailleurs.

## Constat

- Le logo de Minimapperz a un texte blanc : sur la pastille blanche fixe (décision 45.2), « mapperz » disparaît.
- Sur l'accueil, la pastille de la carte fait 96 px de haut quelle que soit la largeur de la carte : le logo tient
  dans une bande plate (« très écrasé »).

## Décisions

1. Réglage par partenaire `logoOnDark` (colonne `partner.logo_on_dark`, faux par défaut), coché en admin sous le
   logo (« Logo clair : l'afficher sur fond sombre »). L'aperçu admin entoure la pastille que le site utilisera.
2. Une seule règle de couleur, `logoChipBackground()` : `bg-neutral-900` si coché, `bg-white` sinon, appliquée
   partout où un logo s'affiche (cartes, bloc discret du récap, overlay, écran de LAN, liste admin). Couleurs fixes
   assumées : c'est le logo qui décide, pas le thème du visiteur.
3. Carte : pastille proportionnelle (5:2) avec marge intérieure ; le logo remplit la place en restant entier
   (`object-contain`).
4. Ajout demandé par Jean en cours de revue : la description courte passe de 160 à 250 caractères
   (`Partner::MAX_DESCRIPTION`, colonne élargie, compteur du formulaire admin).

## Critères d'acceptation

1. API : `logoOnDark` lu à la création et à la modification, rendu dans les listes publique et admin ; migration
   avec `down()`.
2. Front : case dans le formulaire, aperçu qui montre la pastille retenue, `logoChipBackground()` partout.
3. Carte de l'accueil et de `/partenaires` en 5:2.
4. Tests API (domaine, aller-retour admin) et front (couleur, garde tolérante, carte).
5. `composer gates` et `pnpm gates` verts ; vérification réelle.
6. Description courte jusqu'à 250 caractères (API, colonne, formulaire) ; migration avec `down()`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - API et migration.
- [x] **Task 2** (AC 2, 3) - Front.
- [x] **Task 3** (AC 4, 5) - Tests, gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- API : `Partner::create/edit(..., logoOnDark)`, `isLogoOnDark()`, `PartnerDraft::$logoOnDark`, lecture dans
  `PartnerController::draft()`, `logoOnDark` dans `PartnerCatalogQuery` ; migration `Version20261025200006`.
- Front : `logoOnDark?` sur `Partner` (absent = clair), `logoChipBackground()` dans `partner-card.tsx`, appliqué aux
  cartes, au bloc discret, à l'overlay, à l'écran et à la liste admin ; case et aperçu entouré dans le formulaire ;
  carte en 5:2 avec `sizes`.
- Description à 250 caractères : `Partner::MAX_DESCRIPTION`, colonne `VARCHAR(250)` (migration `Version20261025200007`,
  `down()` revient à 160 et échouerait si une description plus longue existe), message d'erreur, compteur et
  `maxLength` du formulaire (`PARTNER_DESCRIPTION_MAX`), bornes du test unitaire.
- Vérification réelle (Chrome, `localhost:3000`) : Minimapperz coché, logo lisible sur fond sombre sur l'accueil,
  carte proportionnée ; formulaire admin avec la case cochée et la pastille sombre entourée. Minimapperz est laissé
  coché (donnée réelle de Jean).

### File List

- `api/migrations/Version20261025200006.php` (nouveau)
- `api/migrations/Version20261025200007.php` (nouveau)
- `api/src/Content/Domain/Entity/Partner.php` (modifié)
- `api/src/Content/Application/Command/PartnerDraft.php` (modifié)
- `api/src/Content/Application/Command/ManagePartners.php` (modifié)
- `api/src/Content/Application/Query/PartnerCatalogQuery.php` (modifié)
- `api/src/Content/Presentation/Controller/PartnerController.php` (modifié)
- `api/tests/Unit/Content/PartnerTest.php` (modifié)
- `api/tests/Functional/PartnerTest.php` (modifié)
- `frontend/src/features/content/partners-api.ts` (modifié)
- `frontend/src/features/content/partner-card.tsx` (modifié)
- `frontend/src/features/content/partner-screen.tsx` (modifié)
- `frontend/src/features/content/partners.test.tsx` (modifié)
- `frontend/src/features/admin/admin-partners.tsx` (modifié)
- `_bmad-output/planning-artifacts/epics/epic-45-partenaires.md` (modifié)
