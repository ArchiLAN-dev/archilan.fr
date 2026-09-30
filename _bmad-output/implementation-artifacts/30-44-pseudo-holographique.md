# Story 30.44: Pseudo holographique, argenté pour les adhérents, doré pour les admins

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 1, 6) - Règle `NameStyle` (domaine, pure : admin, adhérent, activé -> or / argent / null) ;
      `nameStyle` sur les cartes (requêtes brutes de l'annuaire et des classements, via la requête groupée des
      adhésions) et recopie partout où une carte est reconstruite (mêmes endroits que `avatarAnimatedUrl`) ;
      profil public et édition ; tests unitaires et fonctionnels (admin, adhérent, adhésion expirée, rôle retiré,
      désactivé).
- [ ] **Task 2** (AC 2) - Colonne `holo_name` (booléen, défaut vrai) + migration,
      `CommunityProfile::toggleHoloName()` (pas de `set*`), validation dans la mise à jour du profil ; tests.
- [ ] **Task 3** (AC 3, 4, 5) - Front : composant `HoloName` (texte en `background-clip: text`, dégradé
      métallique or / argent, reflet animé ; mode « au survol » pour les cartes ; repli et reduced-motion) ;
      branché sur la page de profil et sur les cartes ; tests.
- [ ] **Task 4** (AC 2) - Case « Pseudo holographique » dans la personnalisation, avec aperçu.
- [ ] **Task 5** (AC 7) - Gates et vérification visuelle (clair / sombre, or / argent, survol, reduced-motion).

## Notes techniques

- Effet en CSS seul (module CSS) : `linear-gradient` métallique + une bande claire en `background-position`
  animée, `background-clip: text`, `color: transparent` avec `@supports` pour le repli ; animation suspendue
  hors survol sur les cartes (`animation-play-state`), coupée sous `prefers-reduced-motion`.
- Les cartes du front qui reçoivent `avatarAnimatedUrl` (30.42) reçoivent `nameStyle` au même endroit ; le
  composant `HoloName` remplace le texte brut du pseudo.
- Rien à voir avec les badges « Adhérent » / « Admin » existants du profil, qui restent.
