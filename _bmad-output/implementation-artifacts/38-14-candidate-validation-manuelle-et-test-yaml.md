# Story 38.14: Valider soi-même une version candidate, et la tester avec un YAML

**Status:** review
**Epic:** 38 - Santé des apworlds
**Date:** 2026-10-02

## Story

En tant qu'admin qui importe une nouvelle version d'apworld,
je veux pouvoir la garder en test jusqu'à ce que je la valide, et la tester avec un YAML de mon choix,
afin de ne mettre en ligne qu'une version que j'ai vérifiée, sans risquer de casser le jeu servi aux joueurs.

## Contexte

Demande de Jean (2026-10-02), à l'occasion d'une mise à jour manuelle de Rogue Legacy. Depuis la story 38.6, un import
manuel devient une **candidate** : le jeu garde sa version, la candidate passe un test de génération solo (template
par défaut) et, **si le test passe, elle est mise en ligne automatiquement** à la passe suivante (5 min). Ce test ne
garantit pas que la version convient à un vrai YAML, et l'admin n'a aucun moyen de l'essayer avant qu'elle remplace la
version en ligne.

Ce qui existe déjà et que la story réutilise :
- les candidates, leur test et leur mise en ligne (`SubmitApworldCandidate`, `DecideApworldCandidates`,
  `PromoteApworldCandidate`, `TriageApworldCandidate`) ;
- la génération de test d'un YAML sur une version précise d'apworld (`RunnerGatewayInterface::startSlotPreflight` /
  `getSlotPreflight`, orchestrateur `POST/GET /preflight-generations`, story 9.42), qui valide aujourd'hui les YAML des
  parties privées.

Aucun changement de l'orchestrateur ni du client PHP partagé.

## Critères d'acceptation

### Validation manuelle

1. À l'import (fichier ou GitHub), une case « Garder en test, je validerai moi-même » marque la candidate.
2. Une candidate marquée dont le test **passe** n'est pas mise en ligne : elle passe au statut **`awaiting`** (testée,
   en attente de validation). Le jeu garde sa version. Un échec ou un test sans template la refuse comme avant.
3. Une candidate en `awaiting` n'expire pas et n'est plus décidée par la passe automatique.
4. Action « Mettre en ligne » (`POST .../apworld-candidate/approve`) sur une candidate en `awaiting` : elle devient la
   version servie, comme une mise en ligne automatique (introspection, incidents réglés, annonce staff, mise à niveau
   des parties). L'annonce staff dit « Validée par X après un test réussi », pas « Forcée ». Sur une candidate qui n'est
   pas en `awaiting` : 409.
5. Un nouvel import remplace une candidate en `awaiting`, comme une candidate en test.
6. La page d'admin du jeu affiche la candidate en `awaiting` avec le bouton « Mettre en ligne ».

### Test avec un YAML

7. Sur une candidate (en test, en attente, refusée ou expirée), l'admin colle un YAML et lance une génération de test
   avec **la version candidate** (`POST .../apworld-candidate/test-yaml`, réponse 202 avec l'identifiant du test). Rien
   n'est mis en ligne, aucun incident n'est ouvert.
8. Le résultat se lit par `GET .../apworld-candidate/test-yaml/{jobId}` : en cours, réussi, ou échoué avec l'erreur
   résumée. Un test inconnu ou expiré (l'orchestrateur garde 30 minutes, en mémoire) répond 404.
9. YAML vide ou trop long (plus de 100 Ko) : 422. Sans candidate : 404. Orchestrateur injoignable : 503.
10. Limite connue, hors périmètre : l'avertissement d'accessibilité (story 38.12) n'est pas remonté par ce test
    (l'orchestrateur ne le transmet pas pour cette génération).

11. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-5) - Domaine et API : statut `awaiting`, marque `hold_for_approval` et `approved_by` + migration,
      décision, action d'approbation, annonce staff ; tests.
- [x] **Task 2** (AC 7-9) - API : démarrer et lire un test YAML sur la candidate ; tests.
- [x] **Task 3** (AC 1, 6, 7, 8) - Front : case à l'import, statut et bouton « Mettre en ligne », panneau de test YAML ;
      tests.
- [x] **Task 4** (AC 11) - Gates.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `ApworldCandidateTest` (+5 : attente puis validation, non marquée par défaut, attente seulement depuis le test, validation seulement depuis l'attente, remplacement d'une candidate en attente) | statut et méthodes absents | `Awaiting`, `awaitApproval`, `approve`, `isHeldForApproval` |
| `DecideApworldCandidatesTest` (+2 : marquée et réussie = attente, marquée et échouée = refus) | pas de branche | décision |
| `AdminApworldCandidateControllerTest` (+8 : mise en ligne validée, 409 hors attente, page du jeu, test YAML sur la version candidate, résultat résumé, test inconnu 404, YAML vide ou trop long 422 / sans candidate 404 / runner 503, admin seulement) | routes absentes | `approve`, `test-yaml` |
| `AdminApworldMinioTest` (+1 : import marqué « garder en test ») | champ ignoré | `holdForApproval` |
| `StaffAlertFactoryTest` (+1 : « Validée par ») | libellé absent | annonce staff |
| front : `admin-games-candidate-api.test.ts` (+4), `apworld-candidate-status.test.tsx` (+2), `candidate-yaml-test.test.tsx` (4) | fonctions et composants absents | appels, statut, panneau |

### Notes

- Statut `awaiting` (la colonne de statut est limitée à 16 caractères), colonnes `hold_for_approval` et `approved_by`
  (migration `Version20261002140000`).
- `findTestingForGame` devient `findPendingForGame` (en test ou en attente) : un nouvel import remplace une candidate
  en attente, et la mise à jour automatique nocturne laisse le jeu tranquille tant qu'une version attend la
  validation de l'admin (même règle qu'une version en test).
- La mise en ligne validée réutilise `PromoteApworldCandidate` sans dérogation (le test a réussi) ; `forcedBy` reste
  vide, `approvedBy` nomme l'admin, et l'annonce staff dit « Validée par X après un test de génération réussi ».
- Le test YAML réutilise `startSlotPreflight` / `getSlotPreflight` avec le hash de la candidate : aucun changement de
  l'orchestrateur ni du client PHP. L'avertissement d'accessibilité n'est pas remonté (AC 10).
- Front : case à l'import (fichier et GitHub), statut « Testée, en attente de ta validation » avec « Mettre en ligne »
  (confirmation, pas de « Forcer » une fois le test réussi), panneau « Tester avec un YAML » sous la candidate
  (sondage toutes les 3 s).

### Gates

- `composer gates` : OK (2579 tests, 15306 assertions).
- `pnpm gates` : typecheck, lint (0 erreur), 682 tests, build OK.

### Vérification visuelle

Non faite : la page d'admin d'un jeu demande une candidate réelle. À regarder sur le serveur de dev (migration
`Version20261002140000`).
