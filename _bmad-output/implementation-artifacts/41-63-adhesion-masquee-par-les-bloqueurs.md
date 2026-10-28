# Story 41.63: La section « Adhérer » masquée par les bloqueurs de pub

**Status:** review
**Epic:** 41 - Pelles et boutique
**Date:** 2026-10-28
**Origine :** Jean le 2026-10-28 : sur `/boutique?onglet=asso#adhesion`, les trois ancres du haut et la section
« Faire un don » s'affichent, mais toute la section « Adhérer » (titre, carte, case CGV) est absente.

## Story

En tant que visiteur qui veut adhérer, avec un bloqueur de pub,
je veux voir la section « Adhérer » de la boutique,
afin de pouvoir payer ma cotisation.

## Contexte

- **Cause, vérifiée sur les listes de filtres le 2026-10-28 :** EasyList (liste par défaut d'uBlock Origin, AdBlock
  Plus, du bloqueur de Brave) contient la règle cosmétique générique `###adhesion`, plus `##.adhesion:not(body)`.
  Tout élément `id="adhesion"` est masqué sur tous les sites.
- `features/payments/support-archilan.tsx` : `<section id="adhesion">` (story 41.13), et l'ancre `#adhesion` dans
  la navigation de la page. Les autres sections (`don`, `articles`) ne sont visées par aucune règle.
- Même piège dans la fiche membre admin : panneau `id="adhesion"` (`admin-user-participation.tsx`, liste
  `SHEET_SECTIONS` de `admin-sheet-section.tsx`, story 36.7). Un admin avec un bloqueur ne voit pas la cotisation.
- Ancre `#adhesion` utilisée par : la redirection `/adhesion`, `community-hub.tsx`, `home-sections.tsx`,
  `weekly-runs-client-page.tsx`.
- Identifiants vérifiés sans règle dans EasyList, Liste FR, AdGuard Français, AdGuard Annoyances, Fanboy
  Annoyances : `adherer`, `cotisation`, `devenir-membre`.
- Précédent : story 42.4, routes `/admin/stats/events` bloquées par les mêmes extensions.

## Acceptance Criteria

1. La section de la boutique s'appelle `id="adherer"` ; la navigation de la page, la redirection `/adhesion` et
   les liens du site pointent vers `#adherer`.
2. Le panneau de la fiche membre admin s'appelle `id="cotisation"`. Une ancienne adresse `#adhesion` ouvre toujours
   l'onglet « Association » (alias dans `resolveSheetTab`).
3. Plus aucun `id="adhesion"` ni classe `adhesion` dans le front (garde-fou : un test qui échoue si l'identifiant
   revient dans les sections de la boutique ou de la fiche).
4. Tests mis à jour ; `pnpm gates` vert.

## Tasks / Subtasks

- [x] Boutique : `adherer` (section, navigation, redirection, liens) (AC 1)
- [x] Fiche admin : `cotisation` + alias `#adhesion` (AC 2)
- [x] Tests et garde-fou (AC 3, 4)
- [x] Gates

## Dev Notes

- Une ancienne URL `/boutique?onglet=asso#adhesion` (marque-page, message Discord) arrive en haut de l'onglet au lieu
  de défiler : acceptable, la section est désormais visible juste en dessous des ancres.
- Pour vérifier un futur identifiant : télécharger `https://easylist.to/easylist/easylist.txt` et chercher
  `^###<id>` et `^##.<classe>`.

## Dev Agent Record

### Completion Notes List

- Réalisation 2026-10-28.
  - `features/payments/support-links.ts` (nouveau) : `SUPPORT_TAB_HREF`, `MEMBERSHIP_ANCHOR = "adherer"`,
    `MEMBERSHIP_HREF`, réexportés par `support-archilan.tsx` ; module à part pour que les composants client
    (`community-hub.tsx`, `weekly-runs-client-page.tsx`) n'embarquent pas les formulaires HelloAsso.
  - Section, navigation, redirection `/adhesion`, liens de l'accueil, du hub communauté et des hebdos passent par
    `MEMBERSHIP_HREF`.
  - Fiche admin : panneau `cotisation` ; `LEGACY_ANCHORS` dans `resolveSheetTab` garde `#adhesion` vers l'onglet
    « Association ».
  - Tests : garde-fou `support-archilan.test.tsx` (ni `id`, ni ancre, ni classe `adhesion`), alias testé dans
    `admin-sheet-section.test.tsx`. `pnpm gates` vert (1279 tests).
