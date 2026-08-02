# Story 32.15 - Chiffres clés en tête de récap

## Status

Done

## Story

**En tant que** visiteur arrivant sur le récap d'une partie,
**je veux** saisir l'ampleur de la partie en une seconde,
**afin de** savoir ce que je regarde avant de plonger dans les graphes.

## Contexte

La page de récap ouvre sur le titre, la durée et la date, puis enchaîne directement sur les
superlatifs et le diagramme des échanges. Rien ne dit si la partie a duré 3 heures à deux ou deux
jours à six, si 200 ou 4000 objets ont circulé, si les joueurs se sont entraidés ou ont surtout
joué chacun dans leur coin. Le lecteur doit reconstituer l'ampleur à partir des graphes, alors que
c'est le contexte qui devrait les précéder.

## Acceptance Criteria

**AC1 - Un bandeau d'indicateurs** est affiché entre l'en-tête et le reste de la page, avec :
durée, nombre de joueurs, objets échangés, part d'objets de progression, checks trouvés,
indices demandés.

**AC2 - Calcul côté serveur.** Les indicateurs sont calculés dans le composant serveur et passés
en props. Aucun recalcul dans un composant client, aucun `useEffect`.

**AC3 - Dégradation honnête.** Un indicateur qu'on ne peut pas calculer n'est pas affiché à zéro,
il est omis. En particulier la part de progression est masquée si **aucun** événement du flux ne
porte de flag (voir Dev Notes) - afficher « 0 % de progression » sur une partie d'avant les flags
serait un mensonge.

**AC4 - Responsive.** Le bandeau passe de six colonnes à deux sur mobile, sans débordement.

**AC5 - Pas de nouvelle dépendance.**

**AC6 - Quality gates.** `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1: fonction pure `buildRecapKeyFigures(recap, feed)` dans `features/recap`, testée (AC1, AC3).
- [x] Task 2: composant `RecapKeyFigures` présentational, sans état (AC1, AC4).
- [x] Task 3: câblage dans `SessionRecapView`, calcul en amont du rendu client (AC2).
- [x] Task 4: tests unitaires sur la dégradation sans flags et sur flux vide (AC3).
- [x] Task 5: gates (AC6).

## Dev Notes

**Sources.** Tout est déjà disponible côté page, aucun changement d'API :

| Indicateur | Source |
|---|---|
| Durée | `recap.durationSeconds` (déjà formatée par `formatDuration`) |
| Joueurs | `recap.podium.length` |
| Objets échangés | nombre d'événements `item-received` du flux |
| Part de progression | `isProgressionFind` (feed-api) sur ces mêmes événements |
| Checks trouvés | somme de `podium[].checksDone` |
| Indices demandés | nombre d'événements de type `hint` (story 32.12) |

**Le piège des flags.** Les flags AP (`item.flags`) ne sont peuplés que depuis la story 32.9. À ce
jour 132 événements en base n'en portent pas, contre 359 qui en ont. Un simple `filter(isProgression)`
compterait donc ces anciens événements comme du remplissage. La règle : si aucun événement de la
partie n'a de flag non nul **et** que tous les `flags` sont `null`, l'indicateur est omis ; sinon il
est calculé sur l'ensemble. Ne pas inventer de valeur intermédiaire.

**Où calculer.** `SessionRecapView` reçoit déjà `recap` et `feed`. Le calcul se fait là, avant le
rendu - c'est un composant serveur, donc aucun coût client (AC-NX1, AC-ST2).
