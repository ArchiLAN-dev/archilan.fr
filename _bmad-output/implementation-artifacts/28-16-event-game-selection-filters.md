# Story 28.16 - Les mêmes filtres dans la sélection de jeux d'une inscription

## Status

Done

## Story

**En tant que** joueur qui s'inscrit à un événement,
**je veux** filtrer le catalogue comme je le fais partout ailleurs sur le site,
**afin de** choisir mes jeux sans repartir de zéro dans un écran qui ne connaît que mon clavier.

## Contexte

Il existe trois surfaces où un joueur choisit des jeux dans le même catalogue. Après 28.15, deux
d'entre elles se comportaient à l'identique - recherche nom + description, plateformes, « mes jeux »
unissant couplage Steam et liste ArchiLAN, « à essayer ». La troisième, la sélection de jeux d'une
inscription à un événement, n'avait qu'un champ de recherche, et il ne cherchait que dans le nom.

C'est pourtant celle qui compte le plus : on y compose une inscription à une vraie LAN, pas un
brouillon de run perso.

Deux causes distinctes, et c'est ce qui fait l'essentiel du travail :

1. **La charge utile de l'API ne portait ni `platforms` ni `steamAppId`.** Sans eux, ni filtre par
   plateforme ni reconnaissance d'une bibliothèque Steam n'étaient possibles côté client, quel que
   soit le soin apporté à l'écran.
2. **Le module de filtrage vivait dans `personal-runs/`.** Le faire consommer par `events/` aurait
   inversé la dépendance entre deux features.

## Acceptance Criteria

**AC1 - La charge utile porte `platforms` et `steamAppId`**, comme celle de la run perso. Un jeu
sans métadonnées de catalogue répond avec une liste de plateformes vide et pas d'app id, plutôt que
d'omettre les clés.

**AC2 - La recherche couvre nom et description**, comme les deux autres surfaces. Chercher
« manoir hanté » y trouvait auparavant zéro résultat.

**AC3 - Filtre par plateforme**, alimenté par les mêmes catégories que le catalogue.

**AC4 - Filtres « Mes jeux » et « À essayer »**, exclusifs entre eux, chacun conditionné à une
source non vide, avec « Mes jeux » unissant couplage Steam et liste ArchiLAN.

**AC5 - Pas de filtre « Récemment joués ».** Cet écran n'a pas d'historique de runs à interroger :
le filtre est absent, pas inventé.

**AC6 - Aucun nouveau point d'entrée de couplage Steam dans le tunnel d'inscription.** Un couplage
fait depuis `/jeux` est honoré ici via le `localStorage`, mais l'écran n'ouvre pas de formulaire de
couplage au milieu d'un parcours payant.

**AC7 - Un seul module de filtrage pour les trois surfaces**, testé, ne connaissant aucun des trois
types de jeu concrets.

**AC8 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: API - `platforms` et `steamAppId` dans `availableGames` de
      `RegistrationGameSelection`, plus l'assertion correspondante en test fonctionnel (AC1).
- [x] Task 2: front - les deux champs dans `AvailableGame` et son parseur, avec repli défensif
      (liste vide / `null`) sur une charge utile ancienne (AC1).
- [x] Task 3: `personal-runs/run-game-filters.ts` devient `games/game-picker-filters.ts`, générique
      sur un `PickableGame` structurel ; la page de run perso est recâblée dessus (AC7).
- [x] Task 4: câblage de `game-selection-gate.tsx` - barre de jetons, plateformes, filtres de
      liste, réinitialisation attendant `settled` (AC2, AC3, AC4, AC5).
- [x] Task 5: tests du module étendus - recherche sur la description, absence du filtre de
      récence, fixture réduite au strict nécessaire (AC2, AC5, AC7).
- [x] Task 6: gates (AC8).

## Dev Notes

**Pourquoi toucher à l'API plutôt que se contenter du possible.** Sans `steamAppId`, « Mes jeux »
aurait reconnu les jeux marqués à la main mais pas ceux d'une bibliothèque couplée : une troisième
réponse à la même question, c'est-à-dire exactement le défaut que cette story corrige. Un demi-filtre
aurait été pire que pas de filtre.

**Le module ne connaît aucun type concret.** `PickableGame` est structurel (`id`, `name`,
`description`, `platforms`, `steamAppId`). `GameSelectionGame` côté run et `AvailableGame` côté
inscription décrivent le même catalogue sans se connaître, et les fonctions sont génériques pour
rendre au appelant son propre type.

**La liste est déjà restreinte par l'événement.** `availableGames` vient de
`gameSelectionConfig`, une sélection d'administrateur par événement, pas du catalogue entier. Les
filtres s'appliquent donc à un sous-ensemble déjà filtré - ce qui reste utile (un événement peut
proposer plusieurs dizaines de jeux) mais explique que la plateforme y soit moins discriminante que
sur `/jeux`.

**Hors périmètre.** Marquer ou démarquer depuis cet écran, et l'URL portant les filtres (28.12 ne
l'a fait que pour le catalogue public, qui est la seule page partageable).
