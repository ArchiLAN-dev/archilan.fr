# Story 30.42: Photo de profil animée sur le profil, au survol ailleurs

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30

## Story

En tant que membre qui parcourt le site,
je veux que les photos animées (GIF d'admin, story 30.40) ne bougent en permanence que sur la page de profil,
et ailleurs seulement quand je passe la souris dessus,
afin que l'annuaire, les classements et les commentaires restent calmes.

## Critères d'acceptation

1. **Page de profil** : la photo d'un admin en GIF reste animée en permanence (comme aujourd'hui).
2. **Partout ailleurs** (annuaire, cartes de membre, amis, commentaires, fil, classements, participants d'une partie
   privée, notifications, menu du compte, en-tête de l'espace membre, personnalisation) : la photo affichée est
   l'image fixe (première image du GIF), et passe au GIF **au survol de la souris**, puis revient à l'image fixe.
3. L'API sert, pour chaque photo de ces surfaces, `avatarUrl` (toujours une image fixe) et `avatarAnimatedUrl` (le GIF
   si le compte est admin et que sa photo est un GIF, sinon `null`).
4. « Réduire les animations » : jamais d'animation, même au survol. Sur mobile (pas de survol), la photo reste fixe.
5. Un compte qui n'est plus admin n'a plus de `avatarAnimatedUrl` : sa photo est fixe partout, profil compris (30.40).
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 3, 5) - Règles `cardAvatarKey` / `animatedAvatarKey`, résolution des cartes, champ
      `avatarAnimatedUrl` dans les charges utiles ; tests.
- [x] **Task 2** (AC 2, 4) - Image d'avatar commune qui passe au GIF au survol ; branchée sur toutes les surfaces ;
      tests.
- [x] **Task 3** (AC 6) - Gates et vérification.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `CustomImageRuleTest::testACardAlwaysShowsAStillAvatar` | règle absente | `cardAvatarKey` |
| `CustomImageRuleTest::testOnlyAnAdminGifHasAnAnimatedVersionForTheHover` | règle absente | `animatedAvatarKey` |
| `CommunityCustomImageTest::testAGifAvatarMovesOnTheProfileOnlyAndOffersItsGifForTheHoverElsewhere` | GIF servi sur les cartes, pas de `avatarAnimatedUrl` | `AvatarUrlResolver::forCard` sur cartes, éditeur, auteurs de commentaire |
| `avatar-image.test.tsx` (3 tests) | composant absent | `AvatarImage` + `avatarSource` |

### Notes

- `AvatarUrlResolver::resolveForRow` / `forCard` renvoient `{avatarUrl, avatarAnimatedUrl}`, étalés dans les cartes
  (annuaire, classements) et recopiés partout où une carte est reconstruite (fil, vue d'ensemble, notifications,
  commentaires, modération, parties privées).
- `CommunityProfileView` : la page de profil garde le GIF animé ; la page des succès et l'éditeur passent par
  `cardAvatar`. Le formulaire de personnalisation prévisualise le GIF (`avatarAnimatedUrl ?? avatarUrl`).
- Front : `AvatarImage` (survol, `prefers-reduced-motion` lu à l'entrée de la souris) remplace les `<img>` d'avatar
  hors page de profil ; champ optionnel `avatarAnimatedUrl` dans les types.
- Fixtures de tests unitaires alignées sur la nouvelle forme de carte (warnings PHPUnit).

### Gates

- `composer gates` : OK (2524 tests, 14964 assertions).
- `pnpm gates` : OK (655 tests, 0 erreur de lint, build propre).
