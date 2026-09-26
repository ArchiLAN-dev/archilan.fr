# Story 38.3: Page Santé des apworlds

**Status:** ready-for-dev
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-09-24
**Dépend de :** 38.1. Les alertes de 38.2 pointent vers cette page.

## Story

En tant qu'admin d'ArchiLAN,
je veux une page qui liste les incidents apworld en cours, qui s'en occupe et depuis quand,
et qui me permet de les prendre en charge, de les résoudre ou de les ignorer,
afin que l'équipe sache à tout moment ce qui est cassé et qui est dessus.

## Contexte

Aujourd'hui, un verdict en échec n'est visible que sur la page admin de **chaque** jeu. Personne ne
parcourt 250 pages de jeu. Cette page rassemble les incidents de 38.1 en un seul endroit : c'est la
destination des alertes de 38.2 et l'outil du « un tel s'en occupe ».

## Critères d'acceptation métier

1. **Liste.** La page `/admin/sante-apworlds` liste par défaut les incidents **actifs** (ouverts et pris
   en charge), les plus anciens d'abord. Pour chacun : jeu (lien vers sa page admin), type, statut,
   ouvert depuis, dernier constat, nombre d'occurrences, admin qui s'en occupe, résumé d'erreur.
2. **Détail de l'erreur.** L'erreur complète stockée est dépliable, sans quitter la page.
3. **Historique.** Un filtre affiche aussi les incidents clos (résolus, ignorés), avec la façon dont ils
   l'ont été (« résolu automatiquement », « résolu par X », « ignoré par X »).
4. **Prendre en charge.** Un bouton « Je m'en occupe » sur un incident ouvert le passe à « pris en
   charge » au nom de l'admin connecté. Sur un incident déjà pris par quelqu'un d'autre, l'action reste
   possible et remplace le nom : c'est une reprise, pas une erreur.
5. **Résoudre et ignorer.** Deux boutons sur un incident actif. Ignorer demande une confirmation et
   rappelle qu'un incident ignoré ne se rouvrira pas pour cette version de l'apworld.
6. **Page du jeu.** La page admin d'un jeu affiche un bandeau quand ce jeu a un incident actif, avec un
   lien vers la page Santé.
7. **Navigation.** La page est accessible depuis le menu admin, avec le nombre d'incidents ouverts non
   pris en charge en pastille.
8. **État vide.** Sans incident actif, la page le dit clairement (« Aucun apworld en échec »), avec la
   date de la dernière réconciliation si elle est connue.

## Critères d'acceptation techniques

9. **Lecture.** `GET /api/v1/admin/apworld-incidents?status=active|closed|all` servi par une requête
   `ApworldIncidentListQuery` (`Application/Query/`, interface + `DbalApworldIncidentListQuery`) qui
   joint le nom du jeu et le nom d'affichage de l'admin. Aucune entité Doctrine renvoyée (AC-A3).
10. **Écriture.** `POST /api/v1/admin/apworld-incidents/{id}/acknowledge`, `/resolve`, `/ignore`, chacun
    appelant **une** commande Application (AC-P4) qui applique la transition de domaine de 38.1, flush,
    puis dispatche l'alerte Discord de 38.2.
11. **Erreurs.** Transition interdite : `409` avec un message lisible. Incident inconnu : `404`.
12. **Accès.** Réservé à `ROLE_ADMIN`, comme les autres endpoints admin de `GameSelection`.
13. **Compteur.** Le nombre d'incidents ouverts non pris en charge est exposé pour la pastille du menu,
    soit par un endpoint dédié léger, soit dans une réponse admin déjà chargée par le shell. Choisir
    celui qui n'ajoute pas de requête à chaque page admin si c'est possible.
14. **Frontend.** `frontend/src/features/admin/admin-apworld-health-page.tsx` + `admin-apworld-health-api.ts`,
    TanStack Query, invalidation de la liste après chaque action. Entrée de menu dans
    `frontend/src/components/admin-shell.tsx`.
15. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/AcknowledgeApworldIncidentTest.php` (idem `Resolve`, `Ignore`) :
   - `testAppliesTheTransitionFlushesThenDispatchesTheStaffAlert`
   - `testForbiddenTransitionIsReportedWithoutDispatch`
   - `testUnknownIncidentIsReportedAsNotFound`
2. `tests/Functional/GameSelection/AdminApworldIncidentControllerTest.php` :
   - `testListReturnsActiveIncidentsOldestFirstWithGameAndAdminNames`
   - `testClosedFilterReturnsClosedIncidentsWithTheirResolution`
   - `testAcknowledgeReturns200AndRecordsTheCurrentAdmin`
   - `testForbiddenTransitionReturns409`
   - `testNonAdminGets403`
3. Frontend : `admin-apworld-health-api.test.ts` (parsing des réponses), puis
   `admin-apworld-health-page.test.tsx` (rendu d'une liste, état vide, actions qui appellent l'API).

## Tasks / Subtasks

- [ ] **Task 1** (AC 10, 11) - Les trois commandes et leurs tests.
- [ ] **Task 2** (AC 9) - Requête de lecture DBAL.
- [ ] **Task 3** (AC 9-12) - Contrôleur admin et tests fonctionnels.
- [ ] **Task 4** (AC 1-5, 8, 14) - Page frontend et client API.
- [ ] **Task 5** (AC 6, 7, 13) - Bandeau sur la page du jeu, entrée de menu et pastille.
- [ ] **Task 6** (AC 15) - Gates.

## Dev Notes

- **Réutiliser l'affichage d'erreur existant.** La page du jeu montre déjà un verdict en échec avec son
  extrait (`admin-game-editor.tsx`, autour de la ligne 1542). Extraire le composant plutôt que le dupliquer.
- **Résumé d'une ligne.** Calculé côté API avec `GenerationFailureParser::summarize()` et renvoyé dans la
  liste, pour ne pas réimplémenter le parsing en TypeScript.
- **Date de dernière réconciliation (AC 8).** Pas de stockage dédié dans cette story : si l'information
  n'est pas disponible simplement, afficher l'état vide sans date plutôt qu'inventer une table.

### References

- [Source: frontend/src/features/admin/admin-moderation-dashboard.tsx] - page admin à liste et actions, modèle de mise en page
- [Source: api/src/GameSelection/Presentation/Controller/AdminGameLibraryController.php] - conventions des endpoints admin du contexte
- [Source: _bmad-output/implementation-artifacts/38-1-incidents-apworld.md]
