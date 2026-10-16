# Story 44.7 - « Tester ma config » avec la ROM

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-16
**Origine :** demande de Jean le 2026-10-16 - le test individuel d'un slot (`/runs/{id}/jeux`) échoue toujours pour un
jeu à ROM : il n'a aucun moyen de recevoir le fichier.
**Dépend de:** 44.2 (transport), 44.5 (panneau), 44.6 (orchestrateur #33 : `requiredFileIds` sur `/preflight-generations`,
client v1.14.0), 38.16 (affichage des erreurs). Branche empilée sur la 38.16.

## Story

En tant que joueur d'une partie perso,
je veux fournir la ROM de mon jeu quand je teste ma config,
afin de vérifier mon YAML avant le lancement, comme pour n'importe quel autre jeu.

## Contexte

- « Tester ma config » : `POST /api/v1/runs/{runId}/participants/me/slots/{slotId}/preflight` →
  `PersonalRunGameSelection::requestSlotPreflight` → `RunSlotPreflightJob` (asynchrone) → `RunSlotPreflightJobHandler::start`
  → `startSlotPreflight($yaml, $hash)`, puis le job se relance pour suivre le verdict.
- L'enregistrement d'un YAML lance aussi un test automatique (`submitSlotPlayerYaml`).
- Les slots de la page portent déjà `requiredFiles` (44.3, `GenerationFiles::ofGame`).
- L'orchestrateur et le client savent passer des fichiers à ce test depuis la 44.6 ; seul le port de l'API les ignorait.

## Décisions

1. Le fichier est déposé pendant la requête (comme au lancement, 44.2) ; seul son id voyage dans `RunSlotPreflightJob`,
   libéré si le runner ne prend pas le test.
2. Pour un jeu dont un fichier est exigé, « Tester ma config » ouvre une modale avec le panneau des fichiers ; le test
   n'est lancé qu'une fois le fichier reconnu (sans lui, il échouerait à coup sûr).
3. Le test automatique à l'enregistrement du YAML n'est pas lancé pour un tel jeu (il échouerait sur le fichier) : le
   verdict précédent est effacé, le joueur teste quand il a sa ROM.

## Critères d'acceptation

1. API : l'endpoint accepte `requiredFiles[<setting>]` (`RequiredGenerationFilesRequest`) ; refus → 422
   `validation_failed` / `required_file_refused` ; les ids passent dans le job puis à `startSlotPreflight`, libérés si le
   runner ne répond pas.
2. API : enregistrer le YAML d'un slot dont le jeu exige un fichier ne lance pas de test et efface le verdict.
3. Front : pour un tel slot, « Tester ma config » ouvre une modale (panneau des fichiers, variante sobre) ; « Lancer le
   test » n'est actif qu'avec le fichier reconnu ; une erreur reste affichée dans la modale.
4. Tests : fonctionnels API (fichier transmis, refus, test automatique sauté), handler (ids passés, libérés),
   msw (multipart) ; gates verts.

## Tâches

- [x] API : job, handler, service, contrôleur, effacement du verdict ; tests.
- [x] Front : API multipart, modale du test du slot ; tests.
- [x] Gates.

## Dev Agent Record

### Completion Notes

- `RunSlotPreflightJob::$requiredFileIds` (défaut vide : un message plus ancien se décode toujours) ; le handler les
  passe à `startSlotPreflight` et les libère si le runner ne répond pas ou si le test est devenu caduc.
- `requestSlotPreflight($runId, $userId, $slotId, $files)` dépose les fichiers avant tout changement ; le contrôleur lit
  `requiredFiles[<setting>]` (422 `validation_failed` / `required_file_refused`).
- Enregistrer le YAML d'un jeu à ROM ne lance plus de test (le verdict précédent est déjà effacé par
  `submitSlotPlayerYaml`).
- Front : `SlotTestFilesDialog` (panneau en variante sobre, « Lancer le test » actif une fois le fichier reconnu),
  `requestSlotPreflight` en multipart avec un résultat typé.
- Gates : `composer gates` (3087 tests, base isolée), `pnpm gates` (1112 tests, build).

### Déploiement

- Un worker messenger démarré avant ce changement ne sait pas décoder le nouveau `RunSlotPreflightJob` (propriété
  inconnue) et s'arrête : redémarrer les workers avec l'API. Constaté en local le 2026-10-16 (slot resté « en cours »).

### File List

- `api/src/PersonalRuns/Application/Message/RunSlotPreflightJob.php`
- `api/src/PersonalRuns/Application/Handler/RunSlotPreflightJobHandler.php`
- `api/src/PersonalRuns/Application/Service/PersonalRunGameSelection.php`
- `api/src/PersonalRuns/Presentation/Controller/PersonalRunController.php`
- `api/tests/Functional/SlotPreflightWithRequiredFileTest.php` (nouveau)
- `api/tests/Unit/PersonalRuns/PersonalRunGameSelectionPreflightTest.php`, `RunSlotPreflightJobHandlerTest.php`
- `frontend/src/features/personal-runs/slot-test-files-dialog.tsx`, `.test.ts` (nouveaux)
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx`, `personal-runs-api.ts`

## Change Log

- 2026-10-16 : « Tester ma config » accepte la ROM.
