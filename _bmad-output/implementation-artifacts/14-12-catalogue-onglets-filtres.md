# Story 14.12: Page catalogue - onglets, filtres par colonne et doublons « AP intégré »

**Status:** review
**Epic:** 14 - APWorld Community Catalogue & Update Tracker
**Date:** 2026-10-15
**Origine :** demande de Jean le 2026-10-15 sur `/admin/catalogue`.

## Story

En tant qu'admin d'ArchiLAN,
je veux que la page de synchronisation du catalogue range chaque tableau dans son propre onglet, filtrable colonne
par colonne, et ne me propose plus de créer des jeux intégrés à Archipelago qui existent déjà,
afin de traiter le catalogue sans faire défiler une page interminable ni trier de faux nouveaux jeux.

## Contexte

- La page empile cinq blocs (nouveaux jeux, ignorés, mises à jour APWorld, stabilité modifiée, retirés du sheet)
  sous un unique champ de recherche par nom.
- Depuis août 2026, l'onglet « Core-Verified Worlds » de la feuille est « une duplication de la liste principale » :
  chaque jeu intégré à AP figure dans les deux onglets (constaté le 2026-10-15 : « Adventure », « Factorio »,
  « A Link to the Past »... sont dans l'onglet principal avec le statut de PR `Core`). Le diff
  (`CatalogSyncService::computeDiff`) n'associe un jeu existant qu'à une seule entrée : l'entrée principale le
  consomme, le doublon de l'onglet intégré ne trouve plus de jeu et apparaît dans « Nouveaux jeux ».

## Critères d'acceptation

1. **Onglets.** La page affiche une sous-navigation en onglets (même style que la page Santé des apworlds) :
   Nouveaux jeux, Mises à jour APWorld, Stabilité modifiée, Ignorés, Retirés du sheet. Chaque onglet affiche son
   compteur et ne montre que son tableau. Le champ de recherche global disparaît au profit des filtres.
2. **Filtres par colonne.** Chaque tableau a une ligne de filtres sous son en-tête :
   - Nouveaux jeux : nom (texte), disponibilité, flags (18+, AP intégré, aucun), votes (avec / sans).
   - Mises à jour APWorld : nom, statut, versions (« version déployée différente de la dernière » / identique).
   - Stabilité modifiée : nom, disponibilité actuelle, nouvelle disponibilité, verrouillage.
   - Ignorés et Retirés du sheet : nom.
   Changer un filtre ramène à la page 1 de la pagination.
3. **Liens des mises à jour.** La colonne « Release » devient « Liens » et contient deux liens : « Release » (si
   connue) et « Configurer », vers le formulaire admin du jeu (`/admin/jeux/{id}`).
4. **Doublons intégrés.** Une entrée présente dans les deux onglets de la feuille n'est plus qu'une seule entrée :
   les données de l'onglet principal (stabilité, liens, 18+, notes) avec le drapeau « AP intégré ». Un jeu déjà créé
   n'apparaît donc plus dans « Nouveaux jeux ».
5. **Jeux verrouillés.** Un jeu à la disponibilité verrouillée dont la stabilité diffère de la feuille reste dans
   « Stabilité modifiée », avec le badge « Verrouillé » et « Appliquer » désactivé (comme le prévoyait la story
   14.9). Il n'en était plus exclu que par le diff (story 14.3), ce qui le faisait disparaître de l'onglet au
   verrouillage. Demande de Jean le 2026-10-15.

## Tâches

- [x] API : fusionner les entrées de même nom (insensible à la casse) des deux onglets dans `CatalogSyncService`
      (chemins API et CSV), test unitaire.
- [x] Front : onglets, filtres par colonne, colonne « Liens » ; logique de filtrage pure testée.
- [x] Gates verts.

## Dev Notes

- Le cache de la feuille (`catalog_sync.sheet`, 1 h) contient encore les doublons jusqu'à expiration ou un
  « Synchro. feuille ».

## Annexe - menu du compte

Demande de Jean le 2026-10-15, livrée sur la même branche : « Mes pelles » (et son solde) quitte le menu déroulant
du compte dans le header. L'entrée « Pelles » de la navigation de `/compte` reste.
