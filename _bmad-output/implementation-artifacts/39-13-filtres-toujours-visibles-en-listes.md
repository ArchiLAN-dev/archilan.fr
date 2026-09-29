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

### Suite (retour de Jean, 2026-09-29)

La liste ouverte d'un filtre s'affichait en boîte blanche : le `select` était transparent (nom dans le contrôle)
et Chrome peint la liste depuis ce fond. Les filtres reprennent le modèle des autres listes de l'admin (annuaire
utilisateurs) : nom au-dessus, `select` natif sur le fond de la page (`bg-background`), flèche native ; la liste
ouverte suit alors le thème sombre (`color-scheme: dark`). Recherche et interrupteur alignés sur ce modèle
(libellé au-dessus, même hauteur). Filtre actif : bordure et valeur violettes, fond inchangé.

### Suite 2 (retour de Jean : « c'est moche »)

Même sombre, la liste ouverte d'un `select` natif reste dessinée par Windows (grise, carrée, hors charte).
Nouveau composant partagé `components/ui/select-field.tsx` sur Radix Select : liste ouverte aux couleurs du
site (panneau sombre, survol, coche sur le choix courant), clavier et lecteurs d'écran conservés. Les filtres et
le tri l'utilisent, avec la mise en page « nom dans le cadre » (« Cible  Tous ▾ ») ; recherche et interrupteur
reviennent à ce format. Vérifié sur le serveur de dev de Jean (`localhost:3000`) : liste ouverte conforme.

### Suite 3 (retour de Jean)

Le statut (En attente / Résolus / Tous ; contributions : En attente / Approuvées / Rejetées / Toutes) devient une
liste comme les autres, en tête de la rangée (« Statut  En attente ▾ ») ; le contrôle segmenté est supprimé. Le
statut compte comme un filtre (`reportFiltersActive`, `contributionFiltersActive`) : mis en évidence hors « En
attente », et « Réinitialiser » le remet à sa valeur par défaut (seul le tri est conservé).

### Suite 4 (retour de Jean : onglets « brouillon »)

Les onglets Signalements / Contributions tutoriels (texte souligné) deviennent une vraie barre d'onglets
(`moderation-tabs.tsx`) : cadre segmenté, icône (drapeau, livre), libellé et pastille du nombre en attente,
onglet courant en relief ; largeur naturelle sur ordinateur, partagée sur téléphone. Vu sur le serveur de dev.

### Suite 5 (retour de Jean : onglets à repenser de zéro)

Choix de Jean parmi quatre maquettes : **deux pages séparées**. Plus d'onglets : `/admin/moderation/signalements`
et `/admin/moderation/contributions`, chacune avec son titre ; une section « Modération » du menu admin porte
les deux entrées et le nombre en attente de chacune (mêmes requêtes et même cache que les pages). L'ancienne
`/admin/moderation` (et `?onglet=contributions`) redirige vers la bonne page en gardant les filtres
(`moderationPathFor`) ; la notification « compte à examiner » mène aux signalements. Supprimés : le tableau de
bord à onglets et la barre d'onglets. Vérifié sur le serveur de dev (redirection, menu, page).
