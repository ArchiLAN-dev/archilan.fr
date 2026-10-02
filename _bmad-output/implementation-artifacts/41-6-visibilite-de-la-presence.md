# Story 41.6: Visibilité de la présence (mode discret)

**Status:** draft
**Epic:** 41 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que membre,
je veux choisir qui peut voir que je suis en train de jouer,
afin de garder la main sur ce que le site dit de moi en direct.

## Contexte

La présence (30.14) est aujourd'hui visible de tous, anonymes compris, sur le profil et le fil :
`DbalCommunityPresenceQuery` ne reçoit même pas le viewer. L'epic 41 l'expose davantage
(41.5, 41.7, 41.11) : il faut d'abord un réglage. Les audiences existent (`Audience::PUBLIC|MEMBERS|FRIENDS`,
`AudiencePolicy::canView`).

## Critères d'acceptation

1. Nouveau réglage de profil « Qui voit quand je joue » : Tout le monde / Membres / Amis / Personne. Valeur par
   défaut : Tout le monde (comportement actuel inchangé pour l'existant).
2. Le réglage s'applique **partout** où la présence est lue : profil, fil, annuaire, 41.5, 41.7, alertes de 41.11.
   L'application se fait côté serveur, dans la requête de présence, jamais seulement dans le front.
3. « Personne » masque aussi la présence aux amis ; le membre se voit toujours lui-même.
4. Un blocage masque la présence quel que soit le réglage (déjà vrai pour la surface sociale, à vérifier pour la
   présence).
5. Le réglage est dans `/compte` (formulaire de personnalisation du profil) avec une phrase d'explication.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [ ] **Domaine/Migration** : enum dédiée `PresenceVisibility` (`everyone`, `members`, `friends`, `nobody`) et
      champ `presence_visibility` sur le profil communautaire. On n'élargit pas `Audience` : `nobody` n'a pas de
      sens pour les sections de profil et serait proposé partout.
- [ ] **Application** : le viewer (tier résolu + blocage) devient un paramètre de
      `CommunityPresenceQueryInterface` ; adapter les appelants existants (`CommunityProfileView`,
      `CommunityFeedQuery`, annuaire).
- [ ] **Front** : champ dans `community-profile-customization-form.tsx`.
- [ ] Tests fonctionnels (chaque niveau x chaque tier de viewer, blocage) et gates.
