# Story 28.12 - Les filtres du catalogue vivent dans l'URL

## Status

Done

## Story

**En tant que** visiteur qui parcourt le catalogue de jeux,
**je veux** retrouver mon filtrage en revenant sur la page,
**afin de** ne pas avoir à le refaire après chaque jeu consulté.

## Contexte

Les cinq filtres du catalogue (`/jeux`) - recherche, disponibilité, « mes jeux », tri, catégories -
vivent uniquement dans l'état React. Ouvrir la fiche d'un jeu puis revenir en arrière remet tout à
zéro. Sur un catalogue qu'on parcourt justement en ouvrant des fiches les unes après les autres,
c'est le geste le plus fréquent qui coûte le plus cher.

Un filtrage est aussi une chose qu'on partage : « regarde les jeux GameCube dispo » n'a aujourd'hui
aucune URL.

## Acceptance Criteria

**AC1 - Chaque filtre s'écrit dans l'URL** : recherche (`q`), disponibilité (`dispo`), « mes jeux »
(`mes-jeux`), tri (`tri`), catégories (`cat`, répétable).

**AC2 - Un retour arrière restaure le filtrage.** Ouvrir une fiche de jeu puis revenir rend la
liste telle qu'elle était.

**AC3 - Une URL propre reste propre.** Un filtre à sa valeur par défaut n'écrit pas de paramètre :
`/jeux` sans filtre ne gagne jamais de `?`. Important pour l'indexation, la page étant en ISR avec
une URL canonique.

**AC4 - L'historique n'est pas pollué.** Filtrer remplace l'entrée courante (`replace`) au lieu d'en
empiler une : sinon dix caractères tapés produiraient dix retours arrière à faire.

**AC5 - La recherche suit le délai d'anti-rebond**, pas la frappe : l'URL est réécrite quand la
recherche l'est, pas à chaque touche.

**AC6 - Une URL forgée ne casse rien.** Une valeur inconnue dans `dispo` ou `tri` retombe sur le
défaut plutôt que de produire un état incohérent.

**AC7 - « Mes jeux » survit au retour.** Le filtre est restauré depuis l'URL sans être écrasé par
la remise à zéro liée au couplage Steam (voir Dev Notes).

**AC8 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: lecture/écriture des cinq filtres dans l'URL, avec omission des valeurs par défaut
      (AC1, AC3, AC4, AC5).
- [x] Task 2: analyse défensive des paramètres entrants (AC6).
- [x] Task 3: `useSteamCoupling` expose l'état « tentative terminée » et la remise à zéro de
      « mes jeux » l'attend (AC7).
- [x] Task 4: `<Suspense>` autour du catalogue - `useSearchParams` l'exige sous rendu statique.
- [x] Task 5: tests des fonctions pures de lecture/écriture des paramètres (AC1, AC3, AC6).
- [x] Task 6: gates (AC8).

## Dev Notes

**Le piège de « mes jeux ».** Le catalogue éteint ce filtre dès que le couplage Steam n'est pas
actif. Or au montage, le couplage automatique n'a pas encore répondu : `coupled` est faux pendant
quelques centaines de millisecondes. Restaurer `mes-jeux=1` depuis l'URL sans précaution le ferait
donc éteindre aussitôt, et l'utilisateur verrait son filtre disparaître sous ses yeux. D'où
l'exposition d'un état « tentative terminée » par le hook : la remise à zéro n'a de sens qu'une fois
qu'on sait que le couplage a échoué, pas tant qu'on l'attend.

**`replace` et non `push`.** L'entrée d'historique de `/jeux` porte donc toujours l'URL filtrée la
plus récente, ce qui est exactement ce qu'un retour arrière doit restaurer, sans imposer dix retours
pour traverser une saisie.

**Pourquoi pas le `sessionStorage`.** Il restaurerait aussi l'état, mais ne rendrait pas le filtrage
partageable ni marquable, et surtout il survivrait à une arrivée délibérée sur `/jeux` sans filtre -
ce qui est déroutant. L'URL est l'état, pas un cache.

**Noms de paramètres** en français, comme le reste des URLs publiques du site (`/jeux`, `/parties`).
