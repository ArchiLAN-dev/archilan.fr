# Story 14.11: Lire la feuille du catalogue par ses en-têtes

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-09-26
**Origine :** signalement de Jean le 2026-09-26 - le lien GitHub n'est jamais repris à la création guidée d'un jeu,
alors qu'il est bien présent sur la ligne de la feuille.

## Story

En tant qu'admin d'ArchiLAN,
je veux que la synchronisation du catalogue lise la feuille « Archipelago Games Sheet » d'après les noms de ses
colonnes,
afin que le lien GitHub, le drapeau 18+ et les notes d'un jeu soient repris correctement, même quand la communauté
réorganise la feuille.

## Contexte

La synchronisation (story 14.5) lit les colonnes par position : 0 nom, 1 stabilité, 2 statut de PR, 3 liens,
4 18+, 5 notes. La feuille a changé depuis (constaté le 2026-09-26 sur la vraie feuille, par l'API) :

| Col. | Onglet « Playable Worlds » aujourd'hui | Ce que le code lisait |
|---|---|---|
| 0 | Game | nom |
| 1 | Stability | stabilité |
| 2 | PR Status | statut de PR |
| 3 | 18+ / Unrated | **liens** |
| 4 | Links & Downloads | **18+** |
| 5 | Setup Guides | **notes** |
| 6-7 | Support, Disclosures | - |
| 8 | Notes | - |

Et la ligne 1 est désormais un bandeau (« Hover over column headers… »), l'en-tête est en ligne 2. L'onglet
« Core-Verified Worlds » a le même bandeau : sa ligne d'en-tête (« Game ») devenait un faux jeu intégré « Game ».

Conséquences : aucun lien GitHub (la colonne 18+ ne contient que `FALSE`), 18+ toujours faux, notes remplacées par
les libellés de « Setup Guides ». La colonne « Links & Downloads » contient pourtant un lien GitHub pour 767 jeux
sur 817.

## Critères d'acceptation

1. **Lecture par en-têtes.** Les colonnes sont trouvées par leur nom d'en-tête (« Game », « Stability »,
   « PR Status », « Links & Downloads », « 18+ / Unrated », « Notes »), insensible à la casse et aux espaces, où
   qu'elles soient dans la ligne.
2. **Ligne d'en-tête détectée**, pas supposée : c'est la première ligne dont une cellule vaut « Game ». Les lignes
   au-dessus (bandeaux) et la ligne d'en-tête elle-même ne sont jamais des jeux.
3. **Onglet des jeux intégrés** : même détection ; seule la colonne « Game » y est lue.
4. **Colonne manquante** : une colonne obligatoire introuvable (« Game », « Stability » pour l'onglet principal)
   ignore l'onglet et le journalise en `error` ; une colonne facultative introuvable (liens, 18+, notes, PR) donne une
   valeur vide et un `warning`. Jamais une valeur lue dans la mauvaise colonne.
5. **Même règle pour l'export CSV de secours** (sans clé Google API).
6. **La lecture des liens ne change pas** : liens multiples dans le texte (`textFormatRuns`) ou lien de cellule.
7. `composer gates` et `pnpm gates` passent.

## Ordre TDD

- `CatalogSyncServiceTest` : la structure réelle du 2026-09-26 (bandeau, en-tête ligne 2, colonnes 0-8) donne le bon
  lien, le bon 18+ et les bonnes notes, en API et en CSV ; l'onglet intégré ne produit pas de jeu « Game » ;
  colonnes dans un autre ordre ; colonne obligatoire absente ; colonne facultative absente.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4, 6) - Carte des colonnes par en-tête, voie API.
- [x] **Task 2** (AC 5) - Voie CSV.
- [x] **Task 3** (AC 7) - Gates.

## Dev Agent Record

- `CatalogSheetColumns` (`Application/Support`, pur) trouve la ligne d'en-tête (la première qui nomme « Game » ou
  « Name », l'ancien intitulé) et la position de chaque colonne, par nom, insensible à la casse et aux espaces. Le
  service l'applique aux deux voies (API et export CSV). « Game » est obligatoire partout, « Stability » sur
  l'onglet principal : sans elles l'onglet est ignoré (`error`) ; une colonne facultative manquante laisse sa
  valeur vide (`warning`).
- La lecture des liens (liens multiples dans le texte, lien de cellule) n'a pas changé.
- Deux fixtures existantes n'avaient qu'un en-tête partiel (« Game » seul) : complétées, comme une vraie feuille.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `CatalogSheetLayoutTest` (structure réelle du 2026-09-26 en API et en CSV, colonnes dans un autre ordre, colonne obligatoire absente, colonne facultative absente) | 5 échecs | verts |
| Fixtures à en-tête partiel | 2 échecs attendus (onglet ignoré faute de « Stability ») | complétées, 60 verts |

### Vérifications

- `composer gates` vert (2273 tests). Front non touché.
- **Vraie feuille (2026-09-26)**, service corrigé avec un vrai client HTTP :
  - voie API : 817 jeux, **767 avec un lien GitHub** (Crystal Project →
    `Emerassi/CrystalProjectAPWorld/releases/latest`), 57 jeux 18+, notes réelles, plus de faux jeu « Game » ;
  - voie CSV (sans clé) : mêmes jeux, 18+ et notes, sans URL (l'export CSV n'en porte pas).