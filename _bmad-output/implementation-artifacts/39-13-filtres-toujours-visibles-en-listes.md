# Story 39.13: Filtres de la modération toujours visibles, en listes déroulantes

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-29

## Story

En tant que membre du staff d'ArchiLAN,
je veux voir et changer les filtres de la modération sans ouvrir de panneau, avec des listes déroulantes,
afin de savoir d'un coup d'œil ce qui est filtré et d'en changer en un clic.

## Contexte

Retour de Jean sur la story 39.12 (2026-09-29) : les filtres cachés derrière le bouton « Filtres » (popover de
boutons en pastilles) sont moins pratiques que des listes déroulantes toujours visibles.

## Critères d'acceptation

1. Sous la ligne recherche + tri, une rangée de **listes déroulantes toujours visibles**, chacune avec son nom
   dans le contrôle (« Cible Tous ▾ ») : Signalements : Cible, Contenu, Commentaire, plus l'interrupteur « Non
   catégorisés » ; Contributions : Cible. Le tri est une liste du même style.
2. Une liste qui filtre (valeur autre que « Tous ») est **mise en évidence** (bordure et fond d'accent).
3. Plus de bouton « Filtres », de popover ni de pastilles : les listes montrent déjà l'état. « Réinitialiser »
   apparaît quand au moins un filtre ou une recherche est actif.
4. Sur téléphone, les listes passent sur deux colonnes, sans défilement horizontal.
5. Rien ne change pour l'URL (story 39.12) ni pour l'API. `pnpm gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 3) - `moderation-toolbar.tsx` : `FilterSelect`, rangée de filtres, réinitialisation ;
      tests.
- [x] **Task 2** (AC 1, 4) - Panneaux Signalements et Contributions sur les listes.
- [x] **Task 3** (AC 5) - Gates et vérification visuelle.

## Dev Agent Record

- `moderation-toolbar.tsx` : plus de popover ni de pastilles. `FilterSelect` (nom dans le contrôle, flèche
  dessinée, mise en évidence bordure `accent-text` + fond `accent/20` + valeur violette quand la valeur n'est pas
  celle par défaut), `FilterToggle` au même format, tri en `FilterSelect`. Rangée de filtres en `flex-wrap` sur
  ordinateur, deux colonnes sur téléphone ; « Réinitialiser » quand un filtre ou une recherche est actif.
- Panneaux : Cible, Contenu, Commentaire et « Non catégorisés » (signalements), Cible (contributions).
- `moderation-filters.ts` : les helpers de retrait de pastille, devenus inutiles, sont supprimés ; les pastilles
  servent encore à savoir si quelque chose filtre.
- Première mise en évidence (`border-accent bg-accent/10`) quasi invisible sur le thème sombre : renforcée après
  la vérification visuelle.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `moderation-toolbar.test.tsx` (filtres visibles sans panneau, `FilterSelect`, réinitialisation) | 6 échecs | 7 verts |

Gates : `pnpm gates` vert (635 tests, build).

### Vérification visuelle

Rendu avec des données d'exemple et le CSS du build, vu dans Chrome : bureau (rangée Cible / Contenu /
Commentaire / Non catégorisés, les filtres actifs en violet, Réinitialiser à droite) et 390 px (tri sous la
recherche, filtres sur deux colonnes, rien de tronqué, aucun défilement horizontal).
