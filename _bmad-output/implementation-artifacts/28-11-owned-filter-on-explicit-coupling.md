# Story 28.11 - Filtrer sur ses jeux dès le couplage Steam

## Status

Done

## Story

**En tant que** joueur qui vient de renseigner son profil Steam,
**je veux** que le catalogue se restreigne immédiatement aux jeux que je possède,
**afin de** ne pas avoir à cliquer un filtre pour obtenir ce que je venais manifestement chercher.

## Contexte

Renseigner son profil Steam badge les jeux possédés, mais laisse le filtre « Mes jeux » éteint :
le joueur doit encore le cliquer. Or coupler sa bibliothèque **est** la demande - personne ne saisit
son profil Steam pour ensuite continuer à parcourir des jeux qu'il n'a pas.

Le point délicat est de ne pas transformer ça en comportement subi. Le couplage a deux déclencheurs
dans `useSteamCoupling` :

- **automatique**, à chaque chargement de page, depuis le compte enregistré ou le `localStorage`
  (garde `autoCoupled`) ;
- **explicite**, quand le joueur soumet le formulaire.

Appliquer le filtre sur le premier reviendrait à réimposer une vue à chaque visite, y compris après
que le joueur l'a délibérément désactivée. La distinction existe déjà dans le code ; cette story s'en
sert au lieu d'ajouter un réglage.

## Acceptance Criteria

**AC1 - Un couplage explicite réussi active le filtre « Mes jeux »** sur la surface où le joueur
vient de saisir son profil.

**AC2 - Un couplage automatique ne touche à rien.** Rechargement, retour sur la page, profil déjà
enregistré sur le compte : le filtre reste tel que le joueur l'a laissé.

**AC3 - Un couplage en échec ne filtre pas.** Profil introuvable, bibliothèque privée, erreur
réseau : rien ne change, et le comportement existant (le filtre retombe si le couplage est perdu)
est conservé.

**AC4 - Le joueur garde la main.** Rien n'empêche de désactiver le filtre juste après ; il n'est pas
réappliqué tant qu'un nouveau couplage explicite n'a pas lieu.

**AC5 - Les deux surfaces concernées** - catalogue public `/jeux` et sélection de jeux d'une run -
se comportent pareil : ce sont les deux endroits qui portent à la fois le formulaire et un filtre
« Mes jeux ».

**AC6 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: `useSteamCoupling` distingue couplage explicite et automatique, et expose un rappel
      déclenché uniquement sur un succès explicite (AC1, AC2, AC3).
- [x] Task 2: le catalogue public active `ownedOnly` sur ce rappel (AC1, AC5).
- [x] Task 3: la sélection de jeux d'une run fait de même (AC5).
- [x] Task 4: tests - explicite active, automatique n'active pas, échec n'active pas (AC1-AC3).
      **La règle a été extraite en fonction pure `shouldApplyOwnedFilter` pour être testable** : le
      projet fait tourner jest en environnement `node` et n'a pas de stack de test de composants, le
      hook lui-même n'est donc pas rendu en test. Ajouter `@testing-library/react` pour trois
      assertions aurait coûté plus que la règle elle-même.
- [x] Task 5: gates (AC6).

## Dev Notes

**Le signal existe déjà, il n'est pas ajouté.** `couple()` est appelée depuis l'effet de pré-remplissage
et depuis `onSubmit`. Il suffit de lui passer l'origine de l'appel plutôt que d'inventer un état
« première fois » persistant : un drapeau en base ou en `localStorage` serait à la fois plus lourd et
faux, puisqu'un joueur qui recouple explicitement demande à nouveau la même chose.

**Pourquoi pas de préférence persistée.** La demande est « la première fois, quand il le demande » -
c'est-à-dire au moment de l'acte, pas un réglage à retenir. Mémoriser « ce joueur veut toujours le
filtre » recréerait exactement le comportement subi qu'AC2 interdit.

**Ne pas court-circuiter la garde existante.** Le catalogue éteint déjà `ownedOnly` quand le couplage
est perdu ; l'activation doit se produire sur le succès du couplage, sinon les deux effets se
combattent au montage.
