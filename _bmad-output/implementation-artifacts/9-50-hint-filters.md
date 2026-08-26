# Story 9.50: Filtrer les indices par priorité et par côté

**Status:** implémentée - PR vers `develop`
**Epic:** 9 - Sessions, slots et indices
**Date:** 2026-08-26
**Issue:** [#260](https://github.com/ArchiLAN-dev/archilan.fr/issues/260)
**Stories liées :** [9.34](9-34-player-set-hint-priority.md) et [9.35](9-35-hint-priority-reaches-ap-server.md) -
elles ont donné au joueur le moyen de classer ses indices, et fait remonter ce classement au serveur
Archipelago. Celle-ci s'en sert enfin à l'affichage.

## Story

En tant que joueur d'une partie ArchiLAN,
je veux filtrer mes indices par priorité et par côté,
afin de retrouver ce que je dois chercher maintenant dans une liste qui en compte quarante.

## Context

Le joueur peut déjà classer un indice - **Prioritaire**, **Faible prio.**, **Éviter**, **Non classé** -
et ce classement part jusqu'au serveur AP (stories 9.34 / 9.35). Mais à l'écran, tout retombe dans la
même liste. Sur une partie à plusieurs joueurs et plusieurs jeux, elle devient assez longue pour que
l'information qu'on vient d'y ranger soit exactement celle qu'on ne retrouve plus.

### Il y a déjà un filtre, il lui manque un axe

`HintsPanel` porte un segmenté **Tous / En attente / Trouvés** :

```ts
const filtered = data.hints.filter((h) => {
  if (filter === "found") return h.found;
  if (filter === "pending") return !h.found;
  return true;
});
```

La story n'ajoute donc pas « un filtre », elle ajoute **deux axes à celui qui existe**.

### Un composant, trois surfaces

`HintsPanel` est monté par la page de slot d'une run privée, celle d'une run hebdo, et la page de
reachability admin. Une seule modification les sert toutes les trois - et doit donc rester correcte
sur les trois, y compris là où l'appelant ne passe pas `onSetStatus`.

### Rien à ajouter côté serveur

`HintEntry` porte déjà tout : `status` / `statusName` (`priority`, `no_priority`, `avoid`,
`unspecified`, `found`), `receivingPlayer` et `findingPlayer`. C'est du filtrage client sur des
données déjà chargées, sans appel supplémentaire.

### Les deux côtés d'un indice

Un indice concerne un slot de deux façons, qui appellent deux gestes différents :

| côté | ce que ça veut dire | ce que le joueur en fait |
|---|---|---|
| `receivingPlayer` = mon slot | un objet **pour moi** est caché quelque part | j'attends, ou je vais le chercher si c'est chez moi |
| `findingPlayer` = mon slot | un objet **pour quelqu'un d'autre** est caché **chez moi** | je vais checker cet endroit pour lui |

Les mélanger, c'est mélanger « ce qui me fait avancer » et « ce que je dois à un autre joueur ».

### Décisions de cadrage (Jean, 2026-08-26)

| Question | Décision |
|---|---|
| Cohabitation | **Deuxième rangée de chips, combinable** avec le segmenté existant. « En attente » + « Prioritaires » est la question que se pose vraiment un joueur en cours de partie ; une rangée fusionnée l'interdirait. |
| Filtre par défaut | **Tout afficher**, comme aujourd'hui. Un filtre actif à l'ouverture est la meilleure façon de faire croire à un indice disparu. |
| Troisième axe | **Oui** : pour moi / dans mon monde / tous. Les données sont déjà là et les deux cas appellent des gestes différents. |

## Acceptance Criteria

### Filtres

1. Une rangée de filtres **priorité** s'ajoute sous le segmenté existant : Toutes, Prioritaires,
   Faible prio., Éviter, Non classé. Les libellés et les couleurs sont ceux que la carte d'indice
   utilise déjà (`STATUS_STYLES`) - un même mot ne doit pas désigner deux choses selon l'endroit.
2. Une rangée de filtres **côté** s'ajoute : Tous, Pour moi, Dans mon monde. « Pour moi » retient les
   indices dont `receivingPlayer` est le slot affiché, « Dans mon monde » ceux dont `findingPlayer`
   l'est. Un indice où le slot est des deux côtés (un objet à soi caché chez soi) apparaît dans les
   deux.
3. Les trois axes se **combinent** : l'état affiché est l'intersection. « En attente » + « Prioritaires »
   + « Pour moi » donne ce qu'il reste à chercher en priorité pour soi.
4. À l'ouverture, **aucun filtre n'est actif** : Tous / Toutes / Tous. La liste est identique à celle
   d'avant la story pour qui ne touche à rien.
5. Le tri de la liste ne change pas. Cette story filtre, elle ne réordonne pas.

### Lisibilité

6. Le compteur d'indices à côté du titre distingue **ce qui est affiché** de **ce qui existe** dès
   qu'un filtre est actif. Un total qui ne correspond pas à la liste sous les yeux est un bug perçu.
7. Quand une combinaison ne retient rien, le message le dit et propose de **réinitialiser les
   filtres**, plutôt que de laisser le joueur chercher lequel des trois l'a vidée.
8. Un axe dont **une seule valeur existe** dans les indices chargés n'affiche pas ses chips : sur un
   slot où rien n'est classé, une rangée de priorités toutes vides n'aide personne.
9. Les trois rangées restent utilisables sur mobile : elles s'enroulent, et chaque cible fait au
   moins 32 px de haut.

### Portée

10. Le comportement est identique sur les **trois surfaces** qui montent `HintsPanel` (run privée,
    run hebdo, page admin). Le filtre par côté a besoin du slot affiché : il est déjà dans
    `HintsData.slot`, aucun appelant n'a à changer.
11. Rien ne change côté serveur, ni dans ce que le panneau reçoit, ni dans ce que le joueur peut
    modifier. Le filtrage est une couche d'affichage : il ne doit jamais faire apparaître un indice
    que l'appelant n'avait pas déjà.

### Gates

12. `pnpm gates` vert. Tests : combinaison des trois axes, état initial sans filtre, message de liste
    vide, masquage d'un axe à valeur unique, et un indice dont le slot est des deux côtés.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5). Les deux axes dans `HintsPanel`, leur combinaison et l'état initial.
- [x] **Task 2** (AC 6-9). Compteur, liste vide avec réinitialisation, masquage d'un axe inutile,
  tenue sur mobile.
- [x] **Task 3** (AC 10-11). Vérification sur les trois surfaces, y compris celle sans `onSetStatus`.
- [x] **Task 4** (AC 12). Tests et gates.

## Dev Notes

- **Tout est déjà dans `HintEntry`.** `statusName` est la clé lisible (`priority`, `no_priority`,
  `avoid`, `unspecified`, `found`), et `HINT_STATUS_NAMES` fait la correspondance avec les entiers
  d'Archipelago. Filtrer sur `statusName` plutôt que sur `status` évite de recopier les valeurs
  numériques d'AP dans une troisième liste.
- **`found` est un statut ET un booléen.** Un indice trouvé porte `found: true` et son `statusName`
  bascule à `found`, ce qui lui fait perdre la priorité que le joueur lui avait donnée. Donc filtrer
  « Prioritaires » sur une liste « Trouvés » ne renverra rien - c'est cohérent, mais c'est
  exactement le cas où l'AC 7 doit parler.
- **Ne pas persister le filtre entre deux chargements.** L'issue évoquait un état gardé « pendant la
  session de consultation » ; le composant est démonté et remonté au changement d'onglet, et un
  filtre qui survit à un rechargement sans que rien ne le montre est un piège. `useState` suffit.
- **Le compteur existant** (`data.hints.length`) est celui qui devient trompeur dès qu'un filtre
  agit : c'est lui que l'AC 6 vise, pas un nouveau.
- **Ce que la story ne fait pas :** trier, changer les priorités disponibles, toucher au budget de
  points, ni filtrer côté serveur.

## Écarts assumés

### La logique de filtrage a quitté le composant

Les trois axes se croisent, et c'est précisément la combinaison qui vaut d'être testée. Or un test
rendu côté serveur ne peut pas cliquer : il n'aurait pinné que la présence des contrôles.

`hint-filters.ts` porte donc les prédicats, `filterHints()` et `axisIsUseful()` en fonctions pures,
testées directement (10 cas), et `HintsPanel` ne garde que l'état et le rendu. L'AC 12 demandait de
tester la combinaison des trois axes et le cas des deux côtés à la fois : sans cette extraction, on
aurait signé sans les couvrir.

### Un seul état plutôt que trois

`useState<HintFilters>` au lieu de trois `useState` séparés. Réinitialiser devient une affectation,
et il n'existe pas d'état intermédiaire où deux axes se contredisent le temps d'un rendu.

## Reste à faire

- **AC 9, la tenue sur mobile** n'a pas été observée sur un vrai écran. Les rangées sont en
  `flex-wrap` et chaque cible fait `min-h-8`, mais trois rangées empilées sur un slot très classé
  méritent un coup d'oeil réel avant de considérer le point comme tenu.

## Change Log

| Date | Version | Description | Auteur |
|---|---|---|---|
| 2026-08-26 | 0.1 | Rédaction de la story | Claude |
| 2026-08-26 | 1.0 | Implémentation ; filtrage extrait en fonctions pures | Claude |
