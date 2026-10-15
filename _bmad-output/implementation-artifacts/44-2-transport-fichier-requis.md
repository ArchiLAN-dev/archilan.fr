# Story 44.2 - Transporter le fichier requis jusqu'au conteneur de génération, sans le stocker

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-15
**Dépend de:** 44.1 (détection, `requiredFile` sur la ligne `###ARCHILAN-FAILURE###`)

## Epic 44 en bref

Certains apworlds lisent un fichier du joueur pendant la génération, presque toujours une ROM. On ne stocke jamais
de ROM : le fichier est demandé dans l'interface avant chaque génération, contrôlé (MD5), transmis au conteneur puis
supprimé.

- 44.1 (mergée, PR #841) : détection et verdict lisible.
- **44.2 (cette story)** : transport temporaire du fichier jusqu'au conteneur (API, orchestrateur, image).
- 44.3 : demande du fichier et contrôle MD5 sur les écrans de génération, admin et joueur.
- 44.4 : régénérations (suivi exact) sans le fichier.

## Story

En tant que lanceur d'une partie (joueur ou admin),
je veux pouvoir joindre le fichier qu'un apworld exige à la génération,
afin que la génération aboutisse, sans qu'ArchiLAN ne conserve jamais ce fichier.

## Contexte (cartographie du 2026-10-15)

**Le problème du temps.** Une run perso ne génère pas dans la requête du joueur : `POST /api/v1/runs/{runId}/start`
(`PersonalRunController.php:184`) dispatche `LaunchPersonalRunJob` (transport AMQP `run_server`), dont le handler crée
la session (`$run->attachSession($sessionId)`, `LaunchPersonalRunJobHandler.php:~154`), la configure, puis
`autoAdvancePersonalRun` appelle `SessionOrchestrator::orchestrateGenerate()`. **L'id de session n'existe donc pas
encore au moment où le joueur envoie son fichier**, et des octets de ROM n'ont rien à faire dans un message AMQP.
Côté admin, `POST /api/v1/admin/sessions/{sessionId}/generate` (`SessionOrchestrationController.php:58`) est
synchrone.

**Décision : dépôt temporaire en mémoire dans l'orchestrateur, identifié par un jeton.**

1. L'API reçoit le fichier dans la requête de l'utilisateur et le pousse aussitôt à l'orchestrateur
   (`POST /required-files`, multipart). L'orchestrateur le garde **en mémoire uniquement** et rend un jeton opaque
   (`stagedFileId`), valable 30 minutes.
2. Seuls le jeton et le nom du réglage voyagent ensuite (message AMQP, puis corps JSON de `/generate`).
3. `Service.Generate` consomme les jetons : les octets partent dans la couche inscriptible du conteneur de
   génération (`/tmp/required-files/`), **jamais dans le volume de session `/data`** (volume nommé persistant
   `archilan_session_{id}`, réutilisé par le serveur AP). Le conteneur est supprimé de force en fin de génération
   (`defer c.Remove`), le fichier avec.
4. Ni MinIO, ni base, ni disque de l'API : le fichier n'est écrit nulle part durablement.

Le même chemin sert l'admin (dépôt puis génération dans la même requête) : un seul mécanisme.

**Côté image.** Archipelago lit le fichier via un réglage `Path` de host.yaml (`DK3Settings.rom_file`, section
`dkc3_options`). `generation_setup.apply_host_gates` sait déjà fusionner une section dans `user_path("host.yaml")`
puis vider `settings.get_settings._cache` ; on réutilise ce patron. La ligne d'échec de 44.1 ne nomme que la
**classe** du groupe (`DK3Settings`), pas la section : la correspondance passe par `_find_settings_groups(world_cls)`
et `world_cls.settings_key`, comme `derive_host_gate_settings`.

## Critères d'acceptation

### Orchestrateur

1. **Dépôt** : `POST /required-files` (Bearer, multipart : `file`, `setting`) garde le fichier en mémoire et rend
   `201 {"stagedFileId": "<jeton aléatoire 128 bits>", "expiresAt": "..."}`.
   - `setting` doit suivre `^\w+\.\w+$` (sinon 400).
   - Taille max 64 Mio par fichier, imposée par `http.MaxBytesReader` (sinon 413). `ParseMultipartForm` seul ne
     plafonne rien.
   - Plafond global des octets en dépôt (env `REQUIRED_FILES_MAX_BYTES`, défaut 512 Mio) : au-delà, 503, rien n'est
     gardé.
   - Le multipart ne doit pas déborder sur disque : lire la partie en flux (`r.MultipartReader()`), pas via
     `ParseMultipartForm` (qui écrit des fichiers temporaires au-delà du seuil mémoire).
2. **Expiration et retrait** : un dépôt non consommé disparaît après 30 minutes (élagage comme le registre des slot
   preflights, `slot_preflight.go:21,112`). `DELETE /required-files/{stagedFileId}` le retire (204, idempotent).
3. **Génération** : `GenerateSessionRequest` gagne `requiredFiles: [{setting, stagedFileId}]` (optionnel, max 4).
   - Jeton inconnu ou expiré : 422 `required_file_expired`, la session reste dans son état (rien n'est lancé).
   - Les jetons sont consommés (retirés du dépôt) dès que `Generate` les accepte : un jeton ne sert qu'une fois.
   - `docker.GenerateMultiworld` copie chaque fichier dans `/tmp/required-files/<n>/<nom d'origine assaini>`
     (second `putArchiveTo`, tar avec entrées de dossier) et ajoute à la commande
     `--required_file <setting>=<chemin>` pour chacun.
   - Rien de ce fichier n'entre dans `buildDataTar`, le volume `/data` ou MinIO.
4. **Nettoyage** : le conteneur `archilan-gen-{id}` est supprimé sur tous les chemins (déjà le cas). La reprise au
   démarrage (`crashInterruptedSessions`, `service.go:355`) supprime aussi un `archilan-gen-{id}` resté d'une session
   `generating` (il contiendrait encore le fichier).
5. **Journaux** : jamais d'octets ni de MD5 du fichier dans les logs ou webhooks. Le nom, le réglage et la taille
   suffisent.
6. Swagger régénéré (`swag init -g cmd/orchestrateur/main.go` depuis `orchestrateur/`, `docs/*` commités).

### Image archipelago

7. `generate_multiworld.py` lit `--required_file <setting>=<chemin>` (répétable) et le retire de `sys.argv` comme
   `--world_directory` (lignes ~571-580), avant `Generate.main()`.
8. `generation_setup.apply_required_files(mapping, world_types)` :
   - trouve le world dont un groupe de settings a pour `__name__` la classe nommée (`DK3Settings`) ;
   - écrit `{world_cls.settings_key: {key: chemin}}` dans `user_path("host.yaml")` (fusion, même code que
     `write_host_gate_yaml`) puis vide `settings.get_settings._cache` ;
   - un réglage qu'aucun world ne déclare n'est pas une erreur : ligne `###ARCHILAN-WARNING###` et on continue
     (la génération échouera alors avec le verdict lisible de 44.1).
   - Appelé après `apply_host_gates` et `disable_file_dialogs`, avant `Generate.main()`.
9. Si le fichier fourni est faux (mauvais MD5 pour un world qui contrôle), l'échec d'Archipelago remonte tel quel
   par la ligne `###ARCHILAN-FAILURE###` ; aucun traitement dédié ici (contrôle MD5 en amont : 44.3).

### API

10. **Port** : `RunnerGatewayInterface` gagne `stageRequiredFile(string $setting, string $filename, string $path):
    string` (rend le jeton) et `releaseRequiredFile(string $stagedFileId): void` ; `generateSession(...)` gagne
    `list<StagedRequiredFile> $requiredFiles = []` (record `setting` + `stagedFileId`). Le flux part du fichier
    temporaire PHP en streaming (`bodyToIterable`, comme `OrchestratorSeedArchiveGateway.php:108-114`), sans
    `file_get_contents` de 64 Mio.
11. **Client orchestrateur** : les appels passent par `archilan/orchestrateur-client` (`sessions()->generate`,
    nouveau `requiredFiles()->stage/release`). Publication d'une nouvelle version mineure (1.12.0) : bumper
    `"version"` du composer.json du paquet avant le tag (voir mémoire « Publication d'un client PHP ») ;
    **accord de Jean requis avant la publication**.
12. **Run perso** : `POST /api/v1/runs/{runId}/start` accepte, en plus de l'appel sans corps actuel, un multipart
    `requiredFiles[<setting>]` (max 4 fichiers, 64 Mio chacun, sinon 422 `validation_failed` sur le champ).
    - Seul le propriétaire de la run (le lanceur) peut joindre un fichier.
    - Les fichiers sont déposés à l'orchestrateur **dans la requête**, avant le dispatch ; `LaunchPersonalRunJob`
      gagne `list<array{setting: string, stagedFileId: string}>` (chaînes seulement) et le handler les passe jusqu'à
      `orchestrateGenerate`.
    - Si le dépôt échoue, rien n'est dispatché, les jetons déjà obtenus sont relâchés, la run reste en l'état.
    - Si la configuration échoue (`resetAfterValidationFailure`), le handler relâche les jetons.
13. **Admin** : `POST /api/v1/admin/sessions/{sessionId}/generate` accepte le même multipart optionnel ; dépôt puis
    génération dans la même requête, relâche des jetons si `generateSession` lève.
14. **Rien n'est conservé côté API** : pas d'entité, pas de MinIO, pas de log des octets ; le fichier temporaire
    PHP d'upload est le seul passage sur disque (supprimé par PHP en fin de requête).
15. Sans fichier joint, tous les parcours restent strictement identiques (JSON de `/generate` inchangé).

### Tests et gates

16. Orchestrateur (`go build ./... && go vet ./... && go test ./...`) : registre de dépôt (taille max, plafond
    global, expiration, consommation unique, retrait) ; construction de la commande avec `--required_file` ;
    tar des fichiers requis hors `/data` ; handler `/required-files` (413, 400, 201) via httptest.
17. Image (pytest) : `apply_required_files` (section trouvée via la classe, fusion host.yaml, cache vidé, réglage
    inconnu = avertissement) ; parsing de `--required_file` ; test AST de `test_generation_setup.py` étendu pour
    exiger l'appel dans `generate_multiworld.py`.
18. API (PHPUnit) : start multipart (propriétaire seulement, tailles, 4 fichiers max, jetons passés au message,
    relâche sur échec) ; admin generate multipart ; parcours sans fichier inchangés. `composer gates` vert.
19. **Vérification de bout en bout** (locale, à noter dans le Dev Agent Record) : run perso DKC3 avec une ROM
    valide, génération réussie ; après coup, aucun conteneur `archilan-gen-*`, aucune trace du fichier dans le
    volume de session ni dans `sessions/{id}/output/archive.zip` (comparer les MD5 des entrées de l'archive à celui
    de la ROM).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 5) - Orchestrateur : registre en mémoire `internal/service/required_files.go` (map
  jeton -> {setting, filename, bytes, expiresAt}, mutex, compteur d'octets, élagage) ; handlers `POST /required-files`
  et `DELETE /required-files/{id}` (`MultipartReader` + `MaxBytesReader`) ; env `REQUIRED_FILES_MAX_BYTES` dans
  `internal/config`.
- [x] **Task 2** (AC 3, 4) - Orchestrateur : `GenerateSessionRequest.RequiredFiles`, `GenerateRequest` porte les
  fichiers résolus ; `GenerateOptions` + `GenerateMultiworld` (second `putArchiveTo` vers `/tmp`, args
  `--required_file`) ; suppression de `archilan-gen-{id}` dans `crashInterruptedSessions`. Extraire la construction
  de commande et du tar en fonctions pures pour les tester (le service tient un `*docker.Client` concret, pas de fake).
- [x] **Task 3** (AC 6) - Swagger.
- [x] **Task 4** (AC 7, 8, 9) - Dépôt archipelago : `apply_required_files` dans `generation_setup.py`, parsing dans
  `generate_multiworld.py`, tests.
- [x] **Task 5** (AC 10, 11) - Paquet `archilan/orchestrateur-client` : `RequiredFilesClient` (stage multipart en
  flux, release), `requiredFiles` dans `generate` ; version 1.12.0 (publication après accord) ; `NullRunnerGateway`
  et `RunnerGateway` à jour.
- [x] **Task 6** (AC 12, 14, 15) - API runs perso : controller start (multipart optionnel), `PersonalRunLifecycle`,
  `LaunchPersonalRunJob`, handler, `PersonalRunAdvancer` / `SessionOrchestrator::orchestrateGenerate`.
- [x] **Task 7** (AC 13) - API admin : `SessionOrchestrationController::generate`.
- [x] **Task 8** (AC 16-19) - Tests, gates des trois dépôts, vérification de bout en bout.

## Dev Notes

### Hors périmètre

- Écrans, saisie du fichier et contrôle MD5 côté navigateur : 44.3. Aucune modification du front ici.
- Savoir à l'avance quels jeux demandent un fichier : l'incident `required_file` (44.1) ne garde que le message
  (`ApworldIncident.php:66`), pas `setting`/`extension`/`md5s`. 44.3 devra persister ces métadonnées (ce sont des
  métadonnées d'apworld, pas la ROM).
- Régénérations du suivi exact (`reachable.py`) : 44.4.
- Runs hebdo (générées par le planificateur, personne pour fournir la ROM) et slot preflight / test de config
  (`POST /preflight-generations`) : non couverts ; ils continuent d'échouer avec le verdict lisible de 44.1.
- Multi : le fournisseur est le lanceur (décision provisoire de l'epic).

### Pièges connus

- **`/data` est persistant** : le volume de session est réutilisé au lancement et n'est supprimé qu'au
  `DeleteSession`. Y déposer la ROM la conserverait des semaines. `PreflightGenerate` (`client.go:607-660`) montre le
  patron `/tmp` + conteneur jetable.
- **Le conteneur de génération est `NetworkMode: none`** : pas de téléchargement possible depuis le conteneur, tout
  passe par `putArchiveTo`.
- **`ParseMultipartForm` n'est pas une limite** (`handlers.go:70`, `session_handlers.go:236`) et écrit sur disque
  au-delà du seuil : pour un fichier qu'on ne doit pas stocker, `MultipartReader` + `MaxBytesReader`.
- **Redémarrage de l'orchestrateur** : les dépôts en mémoire sont perdus ; la génération suivante échoue en 422
  `required_file_expired` côté API, qui doit le traduire en message clair (« Renvoie le fichier »). C'est voulu.
- **Mémoire** : 64 Mio par fichier, plafond global configurable ; le tar en mémoire du `runGeneration` garde déjà
  les entrées tant que la goroutine vit, ne pas dupliquer les octets plus que nécessaire.
- **PHP** : `upload_max_filesize`/`post_max_size` sont à 200M (`Dockerfile:16-17`, `Dockerfile.web:19-20`), assez.
  Traefik ne pose pas de limite de corps connue (à vérifier en prod si un 413 apparaît).
- **Nom de fichier** : assainir (basename, caractères sûrs) avant de l'utiliser comme chemin dans le conteneur ;
  l'extension compte pour certains worlds.
- **Windows / shell** : voir mémoires « Windows shell replaces » et « Bash heredoc vs PowerShell » pour les commits.

### Fichiers à toucher (repères)

- Orchestrateur : `internal/api/router.go:34-70`, `internal/api/session_handlers.go:127-155`,
  `internal/api/types.go:176-183`, `internal/service/session.go:33-42,195-307`, `internal/service/service.go:355`,
  `internal/docker/client.go:369,704-802`, `internal/config`.
- Image : `generate_multiworld.py:571-580,617-623,672`, `generation_setup.py:124-167` (patron host.yaml),
  `tests/test_required_file.py`, `tests/test_generation_setup.py:49-68`.
- API : `src/Sessions/Application/Port/RunnerGatewayInterface.php:134`,
  `src/Sessions/Infrastructure/Http/RunnerGateway.php:412`, `src/Sessions/Infrastructure/Double/NullRunnerGateway.php`,
  `src/Sessions/Application/Service/SessionOrchestrator.php:288-348`,
  `src/Sessions/Presentation/Controller/SessionOrchestrationController.php:58`,
  `src/PersonalRuns/Presentation/Controller/PersonalRunController.php:184` (et l'upload de seed `:464-499` comme
  modèle de validation multipart), `src/PersonalRuns/Application/Command/PersonalRunLifecycle.php:124`,
  `src/PersonalRuns/Application/Message/LaunchPersonalRunJob.php`,
  `src/PersonalRuns/Application/Handler/LaunchPersonalRunJobHandler.php`.
- Paquet : `vendor/archilan/orchestrateur-client/src/Sessions/SessionsClient.php:26,57`, `src/Http/HttpTransport.php`
  (dépôt source du paquet, pas le vendor).

### Tests (patrons existants)

- Go : tests de fonctions pures (`session_test.go`), handlers via httptest + router (`runtime_handler_test.go:40`).
- Python : pytest à plat, faux modules `settings`/`Utils` via `monkeypatch.setitem(sys.modules, ...)`
  (`test_required_file.py`).
- PHP : doubles de services dans le conteneur de test (`NullRunnerGateway`), uploads via
  `new UploadedFile($tmp, 'x.sfc', 'application/octet-stream', null, true)` (`AdminGameApworldTest.php:40`,
  `PersonalRunSeedImportTest.php`).

### Ordre de livraison

Image et orchestrateur d'abord (rétrocompatibles : sans `requiredFiles`, rien ne change), puis client PHP publié,
puis API. Noter les versions dans le CHANGELOG à la release.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- **Image** (`archipelago`, branche `feature/epic-44-story-2-transport-fichier-requis`, commit 63526bc) :
  `generation_setup.take_required_file_args(argv)` retire les `--required_file <Group.key>=<chemin>` de `sys.argv` ;
  `apply_required_files(files, world_types)` écrit `{settings_key: {key: chemin}}` dans host.yaml (même fusion que
  les host gates) puis vide le cache des settings. pytest : 165 verts.
- **Orchestrateur** (branche du même nom, commit e399685) : registre en mémoire `internal/service/required_files.go`
  (jeton 128 bits, 30 min, usage unique, tout ou rien, plafond `REQUIRED_FILES_MAX_MB`, défaut 512, élagué par le
  sweeper) ; `POST /required-files` (lecture en flux `MultipartReader` + `MaxBytesReader`, 413 / 400 / 503) et
  `DELETE /required-files/{id}` ; `requiredFileIds` sur `/generate` (422 `required_file_expired`) ; fichiers copiés
  dans `/tmp/required-files/<i>/` du conteneur de génération, jamais dans `/data` ; la reprise au démarrage supprime
  `archilan-gen-{id}`. Swagger régénéré. `go build`, `go vet`, `go test` verts.
- **Client** (`archilan-orchestrateur-client`, branche `feature/story-44-2-fichiers-requis`, commit 5061280,
  **non poussé, non tagué**) : `requiredFiles()->stage()/release()` (corps en flux), `generate(..., $requiredFileIds)`,
  `RequiredFileExpiredException` ; version 1.12.0. PHPUnit 106 verts, PHPStan vert.
- **API** : `RequiredGenerationFiles` (dépôt tout ou rien, relâche), port `stageRequiredFile` / `releaseRequiredFile`,
  `LaunchPersonalRunJob::$requiredFileIds`, relâche sur tous les chemins qui ne génèrent pas (run absente, seed
  importée, sans slot, configuration en échec, session pas prête, erreur de génération) ; fichier expiré = génération
  en échec avec message lisible (`recordCrash`). `POST /runs/{id}/start` et `POST /admin/sessions/{id}/generate`
  acceptent `requiredFiles[<setting>]` (4 max, 64 Mo) ; seul le propriétaire fournit un fichier (403 sinon).
  `composer gates` vert (3054 tests) **avec le client 1.12.0 copié à la main dans vendor** : `composer.lock` reste à
  mettre à jour après publication du client.
- **Écarts assumés vs AC** : `/generate` reçoit `requiredFileIds: [id]` (le réglage est déjà porté par le dépôt,
  le répéter ouvrait une incohérence) ; plafond en Mo (`REQUIRED_FILES_MAX_MB`) ; un réglage inconnu de l'image est un
  simple `Warning:` sur stderr et non une ligne `###ARCHILAN-WARNING###` (consommée par l'API comme avertissement de
  génération réussie, hors sujet ici) ; un dépôt refusé côté admin répond 503 `runner_unavailable`.
- **Bout en bout (AC 19, 2026-10-15)**, image et orchestrateur locaux reconstruits depuis les branches, apworld DKC3 du
  catalogue (`07b2ffd4...`), ROM USA (En,Fr) au MD5 attendu `120abf30...` :
  - sans fichier : session `crashed`, la ligne d'échec porte `requiredFile` (`DK3Settings.rom_file`, description, MD5) ;
  - avec le fichier déposé (`POST /required-files`, 201) : session `generated`, patch `.apdkc3` de 831 octets ;
  - le même jeton rejoué : 422 `required_file_expired` ;
  - plus aucun conteneur `archilan-gen-*` ; volume de session : aucun fichier au MD5 de la ROM, aucun fichier
    de plus de 3 Mo, aucune entrée de l'archive de sortie au MD5 de la ROM ; session de test supprimée ensuite.
- Client 1.12.0 publié (PR client #14, tag `v1.12.0`), lock API mis à jour ; `composer gates` vert sur le client publié.

### File List

- archipelago : `generation_setup.py`, `generate_multiworld.py`, `tests/test_required_file.py`,
  `tests/test_generation_setup.py`
- orchestrateur : `internal/service/required_files.go` (+ test), `internal/api/required_files_handlers.go` (+ test),
  `internal/docker/required_files_test.go`, `internal/api/router.go`, `internal/api/session_handlers.go`,
  `internal/api/types.go`, `internal/config/config.go`, `internal/docker/client.go`, `internal/service/service.go`,
  `internal/service/session.go`, `internal/service/sweeper.go`, `internal/service/recovery_test.go`, `docs/*`
- client : `src/RequiredFiles/RequiredFilesClient.php`, `src/RequiredFiles/Response/StagedRequiredFile.php`,
  `src/Exception/RequiredFileExpiredException.php`, `src/Http/HttpTransport.php`, `src/OrchestratorClient.php`,
  `src/Sessions/SessionsClient.php`, `tests/RequiredFiles/RequiredFilesClientTest.php`, `composer.json`, `composer.lock`
- api : `src/Sessions/Application/{Service/RequiredGenerationFiles,Support/ProvidedGenerationFile,Exception/RequiredGenerationFileRefusedException,Exception/RequiredGenerationFileExpiredException}.php`,
  `src/Sessions/Application/Port/{RunnerGatewayInterface,PersonalRunAdvancerInterface}.php`,
  `src/Sessions/Application/Service/SessionOrchestrator.php`, `src/Sessions/Infrastructure/Http/RunnerGateway.php`,
  `src/Sessions/Infrastructure/Double/NullRunnerGateway.php`,
  `src/Sessions/Presentation/Controller/SessionOrchestrationController.php`,
  `src/Shared/Presentation/Support/RequiredGenerationFilesRequest.php`,
  `src/PersonalRuns/Application/{Command/PersonalRunLifecycle,Handler/LaunchPersonalRunJobHandler,Message/LaunchPersonalRunJob}.php`,
  `src/PersonalRuns/Presentation/Controller/PersonalRunController.php`,
  `tests/Functional/PersonalRunRequiredFilesTest.php`, `tests/Functional/RunnerGeneratePipelineTest.php`,
  `tests/Unit/PersonalRuns/{LaunchPersonalRunJobHandlerTest,LaunchImportedSeedTest}.php`
