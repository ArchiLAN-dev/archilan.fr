# Story 38.3: Page Santé des apworlds

**Status:** review
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
    appelant **une** méthode d'une commande Application (AC-P4), `TriageApworldIncident`, qui applique la
    transition de domaine de 38.1, flush, puis dispatche l'alerte Discord de 38.2. Une seule classe à trois
    méthodes plutôt que trois classes : le déroulé est identique, seule la transition change.
11. **Erreurs.** Transition interdite : `409` avec un message lisible. Incident inconnu : `404`.
12. **Accès.** Réservé à `ROLE_ADMIN`, comme les autres endpoints admin de `GameSelection`.
13. **Compteur.** Le nombre d'incidents ouverts non pris en charge est exposé pour la pastille du menu,
    soit par un endpoint dédié léger, soit dans une réponse admin déjà chargée par le shell. Choisir
    celui qui n'ajoute pas de requête à chaque page admin si c'est possible. **Retenu :** endpoint dédié
    `GET /api/v1/admin/apworld-incidents/summary` (un `COUNT ... FILTER`), mis en cache une minute côté
    frontend : le shell ne charge aucune réponse admin sur laquelle se greffer.
13 bis. **Bandeau (AC 6).** Lu par le même endpoint de liste, filtré par `?gameId=`, plutôt qu'en
    ajoutant l'incident au payload de détail du jeu : `AdminGameLibrary` reste intact. Toutes les requêtes
    d'incidents partagent le préfixe de clé `["admin-apworld-incidents"]`, qu'une action invalide d'un
    coup (liste, pastille, bandeau).
13 ter. **Liens des alertes.** Les alertes de 38.2 (Discord et in-app) mènent désormais ici.
14. **Frontend.** `frontend/src/features/admin/admin-apworld-health-page.tsx` + `admin-apworld-health-api.ts`,
    TanStack Query, invalidation de la liste après chaque action. Entrée de menu dans
    `frontend/src/components/admin-shell.tsx`.
15. `composer gates` et `pnpm gates` passent.

## Ordre TDD

1. `tests/Unit/GameSelection/TriageApworldIncidentTest.php` :
   - `testAcknowledgeAppliesTheTransitionFlushesThenAlerts`
   - `testResolveRecordsTheAdminAndAlerts`, `testIgnoreRecordsTheAdminAndAlerts`
   - `testForbiddenTransitionIsReportedWithoutCommitOrAlert`
   - `testUnknownIncidentIsReportedAsNotFound`
2. `tests/Functional/AdminApworldIncidentControllerTest.php` :
   - `testListReturnsActiveIncidentsOldestFirstWithGameAndAdminNames`
   - `testClosedFilterReturnsClosedIncidentsWithHowTheyWereClosed`
   - `testGameFilterNarrowsTheList`, `testSummaryCountsActiveIncidentsAndThoseNobodyHasTaken`
   - `testAcknowledgeRecordsTheCurrentAdminAndQueuesTheStaffAlert`, `testResolveAndIgnoreCloseTheIncident`
   - `testForbiddenTransitionReturns409`, `testUnknownIncidentReturns404`, `testNonAdminIsRefused`
3. Frontend (environnement Jest `node`, rendu statique : les composants sont séparés en présentation
   testée et conteneur TanStack Query) :
   - `admin-apworld-health-api.test.ts` : parsing, filtre de jeu, erreurs, actions et message `409` ;
   - `apworld-incident-list.test.tsx` : ce qui a cassé, depuis quand, qui s'en occupe, confirmation
     d'ignorer, incident clos sans action, boutons désactivés pendant l'action, état vide ;
   - `apworld-incident-banner.test.tsx` : bandeau de la page du jeu.

## Tasks / Subtasks

- [x] **Task 1** (AC 10, 11) - `TriageApworldIncident` et ses tests.
- [x] **Task 2** (AC 9) - Requête de lecture DBAL.
- [x] **Task 3** (AC 9-12) - Contrôleur admin et tests fonctionnels.
- [x] **Task 4** (AC 1-5, 8, 14) - Page frontend et client API.
- [x] **Task 5** (AC 6, 7, 13) - Bandeau sur la page du jeu, entrée de menu et pastille.
- [x] **Task 6** (AC 15) - Gates.

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

## Dev Agent Record

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `TriageApworldIncident` | 4 échecs sur 5 | 5 tests |
| Contrôleur admin (fonctionnel) | 8 échecs sur 9, routes absentes | 9 tests du premier coup |
| Liens des alertes vers la page Santé | 3 échecs côté API, 3 côté frontend | verts |
| Client API frontend | 7 échecs sur 10 | 10 tests |
| Liste des incidents | 7 échecs sur 7 | 7 tests |
| Bandeau de la page du jeu | 2 échecs sur 3 | 3 tests |

### Vérifications

- `composer gates` : vert, 2032 tests. `pnpm gates` : vert, 506 tests, build OK (la route
  `/admin/sante-apworlds` est générée). Les 10 avertissements de lint sont ceux déjà présents sur
  `develop`, aucun nouveau.
- **Vérification visuelle dans le navigateur**, sur la pile du worktree (API sur `127.0.0.1:8100`,
  frontend sur `127.0.0.1:3100`, copie de la base locale avec les incidents réels ouverts par la
  réconciliation, admin de test dédié) :
  1. La page liste Crystal Project et Beat Saber, avec leur résumé d'une ligne, « Personne ne s'en
     occupe », et la pastille **2** dans le menu.
  2. « Je m'en occupe » sur Crystal Project : la carte passe à « Pris en charge », « Admin e2e s'en
     occupe depuis le … », le bouton devient « Reprendre », la pastille tombe à **1**. Côté serveur : un
     `POST` puis une seule relecture de la liste et du compteur.
  3. La page admin de Crystal Project affiche le bandeau avec le preneur et le lien vers la page Santé.
  4. Défaut trouvé et corrigé : le lien du bandeau (couleur d'accent sur fond rouge) était à peine
     lisible, il est maintenant en couleur de texte, souligné.
- Précaution : la pile de vérification tournait sur `127.0.0.1` et non `localhost`, pour que le cookie
  de session de test ne remplace pas celui de la session admin réelle sur `localhost:3000`.

### Écarts à la rédaction initiale

- Une commande `TriageApworldIncident` à trois méthodes, pas trois commandes (AC 10).
- Endpoint de compteur dédié (AC 13), bandeau via le filtre `gameId` de la liste (AC 13 bis).
- Composant d'erreur non extrait de `admin-game-editor.tsx` : l'erreur complète tient dans un `<details>`
  natif, sans composant à partager.
- AC 8 : pas de date de dernière réconciliation, comme autorisé par les Dev Notes.

## Corrections de revue (2026-09-26)

- **Historique borné** : les portées « fermés » et « tous » ne rendent que les 200 incidents fermés les plus
  récents (`ApworldIncidentListQueryInterface::HISTORY_LIMIT`), tous les incidents actifs restant listés.
  L historique ne fait que grandir, et chaque ligne porte une trace de plusieurs Ko.
