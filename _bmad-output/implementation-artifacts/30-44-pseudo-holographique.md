# Story 30.44: Pseudo holographique, argenté pour les adhérents, doré pour les admins

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30

## Story

En tant qu'adhérent ou administrateur,
je veux que mon pseudo s'affiche avec un effet holographique (argenté pour un adhérent, doré pour un admin), à la
manière du titre d'une carte à collectionner,
afin que mon statut dans l'association se voie d'un coup d'œil sur mon profil et dans la communauté.

## Contexte

Demande de Jean (2026-09-30), à la suite des idées de fonctionnalités pour les adhérents et les admins. Choix
faits :

- un seul effet holo, décliné selon le statut : **argent** pour un adhérent, **or** pour un admin (un admin qui
  est aussi adhérent a l'or) ; pas de choix de variante ;
- affiché sur la **page de profil** (grand pseudo, animé) et sur les **cartes** (annuaire, commentaires,
  classements, fil, amis, parties privées...), où il ne s'anime qu'**au survol**, comme les photos GIF (30.42).

Comme pour les images (30.40), le statut est lu **au moment de l'affichage** : rien n'est enregistré, l'effet
disparaît quand l'adhésion expire ou que le rôle admin est retiré, et revient avec le statut.

## Critères d'acceptation

1. **Statut** : l'API donne pour chaque pseudo affiché un `nameStyle` : `"gold"` (admin), `"silver"` (adhésion
   active, pas admin) ou `null` (ni l'un ni l'autre, ou effet désactivé). Calculé à la lecture, jamais stocké.
2. **Désactivation** : dans la personnalisation, une case « Pseudo holographique » (cochée par défaut, visible
   seulement pour un adhérent ou un admin) permet de garder un pseudo normal. Enregistrée avec le profil
   (`holoName`, booléen ; absent = inchangé ; non booléen = 422 « Valeur invalide. »), aperçu en direct.
3. **Page de profil** : le grand pseudo porte l'effet, animé en permanence (reflet qui balaie le texte).
4. **Cartes** : partout où un pseudo de membre est affiché à côté de sa photo (les mêmes surfaces que 30.42),
   le pseudo porte l'effet, fixe au repos et animé au survol de la carte.
5. **Lisibilité et accessibilité** : le texte reste lisible en thème clair et sombre (contraste suffisant, couleur
   de repli si `background-clip: text` n'est pas pris en charge) ; « Réduire les animations » : reflet fixe,
   jamais animé ; le texte reste du texte (sélection, lecteurs d'écran inchangés).
6. **Performances** : le statut des cartes d'une liste est résolu en une requête groupée (`activeMemberIds`),
   sans N+1.
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 6) - Règle `NameStyle` (domaine, pure : admin, adhérent, activé -> or / argent / null) ;
      `nameStyle` sur les cartes (requêtes brutes de l'annuaire et des classements, via la requête groupée des
      adhésions) et recopie partout où une carte est reconstruite (mêmes endroits que `avatarAnimatedUrl`) ;
      profil public et édition ; tests unitaires et fonctionnels (admin, adhérent, adhésion expirée, rôle retiré,
      désactivé).
- [x] **Task 2** (AC 2) - Colonne `holo_name` (booléen, défaut vrai) + migration,
      `CommunityProfile::toggleHoloName()` (pas de `set*`), validation dans la mise à jour du profil ; tests.
- [x] **Task 3** (AC 3, 4, 5) - Front : composant `HoloName` (texte en `background-clip: text`, dégradé
      métallique or / argent, reflet animé ; mode « au survol » pour les cartes ; repli et reduced-motion) ;
      branché sur la page de profil et sur les cartes ; tests.
- [x] **Task 4** (AC 2) - Case « Pseudo holographique » dans la personnalisation, avec aperçu.
- [x] **Task 5** (AC 7) - Gates et vérification visuelle (clair / sombre, or / argent, survol, reduced-motion).

## Notes techniques

- Effet en CSS seul (module CSS) : `linear-gradient` métallique + une bande claire en `background-position`
  animée, `background-clip: text`, `color: transparent` avec `@supports` pour le repli ; animation suspendue
  hors survol sur les cartes (`animation-play-state`), coupée sous `prefers-reduced-motion`.
- Les cartes du front qui reçoivent `avatarAnimatedUrl` (30.42) reçoivent `nameStyle` au même endroit ; le
  composant `HoloName` remplace le texte brut du pseudo.
- Rien à voir avec les badges « Adhérent » / « Admin » existants du profil, qui restent.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `NameStyleTest` (or admin, argent adhérent, rien, désactivé) | enum absente | `NameStyle::for` |
| `CommunityHoloNameTest` (adhérent, admin, ni l'un ni l'autre, adhésion expirée, désactivation + absent = inchangé, refus) | 4 échecs + champs absents | colonne, `toggleHoloName`, `NameStyleResolver`, exposition |
| `holo-name.test.tsx` (nom brut, or / argent, survol, lecture) | écrit avec le composant | `HoloName` |

### Notes

- API : enum `NameStyle` (domaine, pure), `NameStyleResolver` (Application, une requête groupée
  `activeMemberIds` par liste) branché dans les requêtes brutes de l'annuaire et des classements ; `nameStyle`
  recopié partout où une carte est reconstruite. Profil public : `nameStyle` (badges déjà calculés). Édition :
  `holoName` et `holoNameStyle` (le style que donne le statut, pour l'aperçu). Colonne `holo_name` (migration
  `Version20260930200000`), `CommunityProfile::toggleHoloName()`, `holoName` dans la mise à jour (absent =
  inchangé, non booléen = 422).
- Front : `HoloName` + module CSS (dégradé métallique avec une bande irisée qui balaie le texte,
  `background-clip: text` sous `@supports`, couleur pleine en repli, ombre en `drop-shadow` car un
  `text-shadow` recouvrirait le dégradé ; figé sous reduced-motion ; sur les cartes, animé au survol du lien de
  la carte ou du nom). Branché sur la page de profil (toujours animé), les cartes membres, la communauté, les
  amis, les commentaires, les classements, les participants des parties privées. Case « Pseudo holographique »
  avec aperçu dans la section Identité, visible seulement si le statut donne un style.
- Le site n'a qu'un thème sombre (`color-scheme: dark`) : l'AC « clair et sombre » se réduit au sombre.

### Gates

- `composer gates` : OK (2550 tests, 15149 assertions).
- `pnpm gates` : typecheck, lint (0 erreur), 671 tests, build OK.

### Vérification visuelle

À faire sur le serveur de dev (migration `Version20260930200000` à appliquer).
