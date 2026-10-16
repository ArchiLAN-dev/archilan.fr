# Story 44.6 - Tester la génération d'un jeu à ROM depuis sa fiche admin

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-16
**Origine :** demande de Jean le 2026-10-16 - l'admin doit pouvoir vérifier qu'une génération passe pour valider
un jeu comme Donkey Kong Country 3, ce qu'aucun test de la fiche ne permet tant que le fichier n'est pas fourni.
**Dépend de:** 44.2 (transport, staging), 44.3 (`neededAtGeneration`), 44.5 (panneau partagé, PR #849 - à merger
avant de commencer).
**Dépôts touchés :** `archilan.fr` (api, frontend), `orchestrateur` (Go), `archilan-orchestrateur-client` (PHP).

## Story

En tant qu'admin d'ArchiLAN,
je veux fournir le fichier exigé (la ROM) aux tests de génération de la fiche d'un jeu,
afin de valider un jeu à ROM sur une vraie génération, sans passer par « Autoriser malgré l'échec » ni lancer une
partie perso.

## Contexte (cartographie du 2026-10-16)

**Les deux tests de la fiche ne transportent aucun fichier :**
- **Test de l'apworld** (9.38) : `POST /api/v1/admin/games/{gameId}/apworld-preflight`
  (`AdminGameLibraryController.php:446`, JSON, 202 `pending`) → `AdminGameLibrary::rerunApworldPreflight` (:446) →
  `RunnerGatewayInterface::runApworldPreflight($hash)` → orchestrateur `POST /apworlds/{hash}/preflight`
  (`handleRunApworldPreflight`, `handlers.go:224`) → `Service.RunApworldPreflight` / `StartApworldPreflight`
  (`service/apworld_preflight.go:90/132`) → `docker.PreflightGenerate(ctx, apworldData, hash, playerYaml)`
  (`docker/client.go:638`).
- **Test YAML** (38.14) : `POST .../apworld-candidate/test-yaml` (`AdminApworldCandidateController.php:71`) →
  `StartApworldCandidateYamlTest.php:40` → `startSlotPreflight($yaml, $hash)` → client
  `Preflight/PreflightClient::start` → orchestrateur `POST /preflight-generations` (`handleStartSlotPreflight`,
  `handlers.go:401`) → `StartSlotPreflight(yaml, hash)` (`service/slot_preflight.go:34`).

**Ce que la génération d'une session sait déjà faire (44.2), à réutiliser :**
- API : `RequiredGenerationFilesRequest::read($request)` (multipart `requiredFiles[<setting>]` → `{files, errors}`),
  `RequiredGenerationFiles::stage()` (tout ou rien, renvoie les ids) / `release()`,
  `RequiredGenerationFileRefusedException` → 422 `required_file_refused`.
- Client : `SessionsClient::generate` ajoute `requiredFileIds` au JSON quand la liste n'est pas vide ;
  `HttpTransport.php:240` traduit le 422 `required_file_expired`.
- Orchestrateur (`service/session.go`) : contrôle du nombre (`MaxRequiredFilesPerGeneration`, :204), `has` (:207),
  `take` (:228), puis `GenerateOptions{RequiredFiles: dockerRequiredFiles(...)}` ; copie dans
  `/tmp/required-files/<i>/<nom>` (`requiredFilesTar`, `docker/client.go:791`) et arguments de commande (:779).

**Verdict de l'apworld :** `storage.ApworldPreflight` (`storage/client.go:39`), stocké par hash dans les
métadonnées de l'apworld : `Status` (pending|passed|failed|skipped), `Error`, `Warning`, `CheckedAt`, `Overridden`,
`Image`, `ImageID`. Rien ne dit qu'un fichier a été fourni.

**Test tournant (38.9) :** `SweepApworldCatalog::sweep` (:39-90) + `CatalogSweepPlanner::plan` relancent
`runApworldPreflight($hash)` sans fichier, selon l'image et l'âge du verdict. Un échec écrase le verdict ;
`ReconcileApworldIncidents` reconnaît un fichier manquant (`GenerationFailureParser::requiredFile`), appelle
`RecordGenerationRequiredFile::record` (:146) et ouvre un incident `RequiredFile`. Un jeu validé avec sa ROM
repasserait donc en échec au tour suivant.

**Front :** fiche `admin-game-editor.tsx` - `handleRerun` (:1776), bouton « Relancer le test » (:1824),
`<CandidateYamlTest>` (:1747, `candidate-yaml-test.tsx`). Helpers `rerunApworldPreflight` (:252),
`startApworldCandidateYamlTest` (:344) dans `admin-games-api.ts`. Panneau partagé (44.5) :
`games/required-files-panel.tsx` (`useProvidedFiles`, `RequiredFilesPanel`) et `games/required-file-check.ts`
(`requiredFilesForm`, `launchBlocker`, `filesToProvide`...).

## Décisions

1. **Seuls les fichiers `neededAtGeneration`** sont demandés, comme au lancement (44.5). Un fichier seulement
   déclaré n'est pas proposé.
2. **Fournir le fichier est facultatif pour lancer un test** : sans fichier, le test part comme aujourd'hui (et
   échoue sur le fichier manquant, message nommé). Le panneau dit clairement que le test échouera sans lui.
3. **Option b (Jean, 2026-10-16) : un verdict « passé avec fichier » reste valable tant que l'apworld ne change
   pas.** Le verdict porte `withRequiredFiles: bool`. Le test tournant ne relance jamais un apworld dont le verdict
   est `passed` + `withRequiredFiles` : il ne peut pas fournir le fichier, son échec ne dirait rien. Un nouvel apworld
   (nouveau hash) repart sans verdict, comme aujourd'hui. Un changement d'image ne le remet pas en test (limite
   assumée : l'admin relance à la main après une montée d'image, la fiche le signale déjà via
   `apworldPreflightOnCurrentImage`).
4. **Jamais de stockage** : même transport que la 44.2 (jeton à usage unique, `/tmp` du conteneur, supprimé ensuite).

## Critères d'acceptation

### Fiche jeu (front)

1. Quand le jeu a au moins un fichier `neededAtGeneration`, le panneau « Fichiers à fournir » (composant de la
   44.5, contrôle MD5 navigateur identique) apparaît dans la section du test de génération, au-dessus de
   « Relancer le test », avec la mention « Sans ce fichier, le test échouera. »
2. « Relancer le test » envoie les fichiers prêts (multipart `requiredFiles[<setting>]`), ou un appel sans corps
   comme aujourd'hui s'il n'y en a pas. Un fichier refusé (MD5, taille) bloque le bouton avec la raison.
3. Le test YAML (`CandidateYamlTest`) propose le même panneau et envoie les fichiers avec le YAML.
4. Le verdict affiche « Passé avec <libellé du fichier> » quand `withRequiredFiles` est vrai.
5. Les erreurs de l'API (422 `validation_failed` sur `requiredFiles`, 422 `required_file_refused`, 503) sont
   affichées. Le fichier choisi est gardé pour réessayer, puis oublié une fois le test accepté (44.5, décision 3).

### API

6. `POST .../apworld-preflight` et `POST .../apworld-candidate/test-yaml` acceptent, en plus de leur forme actuelle,
   un multipart `requiredFiles[<setting>]` lu par `RequiredGenerationFilesRequest::read`. Les fichiers sont déposés
   par `RequiredGenerationFiles::stage()` puis passés au runner ; en cas d'échec de l'appel au runner, les ids sont
   libérés (`release`).
7. Ports : `runApworldPreflight(string $hash, array $requiredFileIds = [])` et
   `startSlotPreflight(string $playerYaml, ?string $apworldHash, array $requiredFileIds = [])`.
8. `withRequiredFiles` est exposé dans le verdict de la fiche (`preflightPayload`) et dans `fetchApworldPreflights`.
9. Test tournant : `SweepApworldCatalog` / `CatalogSweepPlanner` écartent les verdicts `passed` +
   `withRequiredFiles` (nouveau cas dans la liste des exclusions, test du planner).

### Client PHP (`archilan-orchestrateur-client`)

10. Les deux méthodes transmettent `requiredFileIds` dans le JSON quand la liste n'est pas vide (même règle que
    `SessionsClient::generate`), et le 422 `required_file_expired` est traduit comme pour la génération. Bump de
    `version` dans `composer.json` avant le tag (mémoire « Publication d'un client PHP »), accord de Jean avant
    publication.

### Orchestrateur

11. `POST /apworlds/{hash}/preflight` et `POST /preflight-generations` acceptent `requiredFileIds` : mêmes contrôles
    que la génération (nombre, `has`, `take`), fichiers copiés dans `/tmp/required-files/` du conteneur de test via
    `PreflightGenerate`, puis oubliés.
12. `storage.ApworldPreflight` gagne `WithRequiredFiles bool` (JSON `withRequiredFiles`), vrai quand le test a reçu
    au moins un fichier. Un verdict existant sans le champ se lit `false`.
13. Tests Go : handler (ids inconnus, trop d'ids), service (le fichier arrive dans les options du conteneur), verdict.

### Vérification réelle

14. Base de dev, image et orchestrateur à jour : fiche Donkey Kong Country 3 → sans fichier, le test échoue avec le
    message nommé ; avec la vraie ROM (MD5 `120abf304f0c40fe059f6a192ed4f947`), il passe et affiche « Passé avec ... » ;
    un passage du test tournant ne le relance pas. Test YAML idem. Noter dans le Dev Agent Record.

## Tâches

- [x] **Orchestrateur** (AC 11-13) - ArchiLAN-dev/archilan-orchestrateur#33 ; tag et image à faire au merge.
- [x] **Client PHP** (AC 10) - ArchiLAN-dev/archilan-orchestrateur-client#16 mergée, tag `v1.14.0` (accord de Jean
      le 2026-10-16).
- [x] **API** (AC 6-9) - ports, adapter, contrôleurs, commande du test YAML, test tournant ; tests unitaires et fonctionnels.
- [x] **Front** (AC 1-5) - helpers `admin-games-api.ts` en multipart, panneau dans la section du test et dans
      `CandidateYamlTest`, libellé du verdict ; tests (msw, rendu statique).
- [ ] **Gates** `composer gates`, `pnpm gates`, tests Go : verts. **Vérification réelle** (AC 14) à faire : orchestrateur
      de la #33 déployé en local, vraie ROM DKC3 (voir Dev Agent Record).

## Dev Notes

- Branche `feature/epic-44-story-6-tests-admin-fichier-requis` depuis `develop`, **après le merge de la #849**
  (le panneau vient de la 44.5).
- Local : l'API doit écouter en IPv4 (`php -S 127.0.0.1:8000`) et un worker messenger doit tourner, sinon les
  webhooks de l'orchestrateur et les jobs ne passent pas (mémoire « Local dev - run launch pitfalls »).
- Hors périmètre : runs hebdo, test de config d'un slot de joueur (`RunSlotPreflightJob`), régénérations du suivi
  exact (44.4).

## Dev Agent Record

### Implementation Plan

- Orchestrateur : `PreflightGenerate(..., requiredFiles)` copie les fichiers comme `GenerateMultiworld`
  (`preflightCommand` / `requiredFileArgs` partagés avec `generateCommand`). `StartApworldPreflight(hash, ids)` et
  `StartSlotPreflight(yaml, hash, ids)` prennent les fichiers (`takeRequiredFiles`) avant tout changement : un id
  inconnu ou expiré refuse le test (422 `required_file_expired`) sans toucher au verdict. `recordProvidedFiles` pose
  `WithRequiredFiles` sur un verdict terminé (jamais sur `skipped`), `markPending` le remet à faux.
- Le corps `[]` que le client PHP envoie quand il n'a rien à dire est lu comme « aucun fichier » (sinon le bouton
  existant et le test tournant auraient pris un 400).
- API : `AdminGameLibrary::rerunApworldPreflight($gameId, $files)` et `StartApworldCandidateYamlTest::start(..., $files)`
  déposent les fichiers par `RequiredGenerationFiles` (tout ou rien) et les libèrent si le runner ne prend pas le
  test. Le contrôleur du test YAML lit le YAML en JSON ou en champ multipart.
- Front : `admin-game-test-files.ts` (fichiers à demander, blocage seulement sur un fichier en vérification ou refusé,
  libellé « réussi avec ... ») ; le panneau partagé de la 44.5 gagne `intro` et des ids `useId` (deux panneaux sur
  la fiche).

### Completion Notes

- Go : `go test ./...` vert. Client : phpunit + phpstan verts. API : `composer gates` vert (3078 tests sur base
  isolée). Front : `pnpm gates` vert (1088 tests, build).
- Vérifié dans le navigateur (fiche DKC3, onglet APWorld) : le panneau « Fichiers à fournir » s'affiche sous le
  verdict avec la mise en garde « Sans ce fichier, le test échouera. ».
- Reste AC 14 : il faut l'orchestrateur de la #33 (conteneur local reconstruit) et la vraie ROM pour une génération
  de bout en bout.
- Un champ texte multipart arrive en CRLF (règle de `FormData`) : sans effet sur un YAML.

### File List

- `api/composer.json`, `api/composer.lock` (client 1.14.0)
- `api/src/GameSelection/Application/Command/ApworldCandidateYamlTestOutcome.php`
- `api/src/GameSelection/Application/Command/StartApworldCandidateYamlTest.php`
- `api/src/GameSelection/Application/Command/SweepApworldCatalog.php`
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php`
- `api/src/GameSelection/Presentation/Controller/AdminApworldCandidateController.php`
- `api/src/GameSelection/Presentation/Controller/AdminGameLibraryController.php`
- `api/src/Sessions/Application/Port/RunnerGatewayInterface.php`
- `api/src/Sessions/Infrastructure/Double/NullRunnerGateway.php`
- `api/src/Sessions/Infrastructure/Http/RunnerGateway.php`
- `api/tests/Functional/AdminApworldCandidateControllerTest.php`
- `api/tests/Functional/AdminGameTestWithRequiredFileTest.php` (nouveau)
- `api/tests/Unit/GameSelection/BuildsAdminGameLibrary.php`
- `api/tests/Unit/GameSelection/SweepApworldCatalogTest.php`
- `frontend/src/features/admin/admin-game-editor.tsx`
- `frontend/src/features/admin/admin-game-test-files.ts` (nouveau), `.test.ts` (nouveau)
- `frontend/src/features/admin/admin-games-api.ts`
- `frontend/src/features/admin/candidate-yaml-test.tsx`, `.test.tsx`
- `frontend/src/features/games/required-files-panel.tsx`
- Orchestrateur (#33) : `internal/api/{handlers.go,types.go,preflight_files_handler_test.go}`,
  `internal/docker/{client.go,required_files_test.go}`, `internal/service/{apworld_preflight.go,required_files.go,
  runtime.go,runtime_test.go,service.go,slot_preflight.go,slot_preflight_test.go}`, `internal/storage/client.go`, `docs/*`
- Client (#16) : `src/Apworlds/{ApworldsClient.php,Response/ApworldPreflight.php}`, `src/Preflight/PreflightClient.php`,
  tests, `composer.json`

## Change Log

- 2026-10-16 : implémentation (orchestrateur #33, client v1.14.0, API et front) ; vérification réelle en attente.
