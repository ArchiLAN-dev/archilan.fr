# Story 44.3 - Savoir avant la génération quel fichier un jeu demande

**Status:** done
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-15
**Dépend de:** 44.1 (verdict `requiredFile`), 44.2 (transport, mergée : API #847, orchestrateur #31, image #35, client v1.12.0)

## Epic 44 en bref

On ne stocke jamais de ROM. Le lanceur fournit le fichier avant chaque génération ; il est contrôlé (MD5),
transmis au conteneur, puis supprimé.

- 44.1 (mergée) : détection et verdict lisible.
- 44.2 (mergée) : transport temporaire jusqu'au conteneur.
- **44.3 (cette story)** : métadonnées - quel jeu demande quel fichier, avant toute génération.
- 44.5 : écrans joueur et admin (sélection du fichier, MD5 navigateur, envoi au lancement). Découpée de l'ancienne
  44.3 le 2026-10-15.
- 44.4 : régénérations du suivi exact sans le fichier.

## Story

En tant que lanceur d'une partie (joueur ou admin),
je veux que l'API sache, avant que je lance, quel fichier chaque jeu de la partie demande (nom, extension, MD5
acceptés) et s'il est indispensable à la génération,
afin que l'écran de lancement (44.5) puisse me le demander et le contrôler au lieu de me laisser découvrir un échec.

## Contexte (cartographie du 2026-10-15)

**Aujourd'hui rien n'est stocké.** Le seul producteur de `requiredFile` est une génération qui échoue. L'incident
`required_file` (44.1) ne garde que le message (`ReconcileApworldIncidents.php:143-144` passe
`RequiredGenerationFile::message()`) ; `setting` et `md5s` sont perdus.

**Décision (Jean, 2026-10-15) : introspection + constat.**
- *Déclaré* : l'image liste statiquement, pour chaque world, les réglages `Path` marqués `required` de ses groupes de
  settings (description, extension, MD5). Cela couvre aussi les jeux officiels livrés avec l'image (ALttP, OoT...).
  C'est un sur-ensemble : 56 worlds déclarent un fichier, 8 seulement en ont besoin pour générer, les autres ne
  s'en servent que côté client.
- *Constaté* : un réglage n'est **indispensable à la génération** qu'une fois qu'une génération a échoué dessus
  (verdict `requiredFile`, quel que soit le chemin). 44.5 l'exigera ; un fichier seulement déclaré restera
  optionnel à l'écran.

**Bug trouvé en route (à corriger ici, sinon le constat ne marche pas) :** l'orchestrateur coupe chaque ligne de
stderr d'un verdict de test à 300 caractères (`apworld_preflight.go:32`, `preflightErrorExcerpt`). La ligne
`###ARCHILAN-FAILURE###` de DKC3 fait environ 430 caractères : coupée, son JSON ne se décode plus et
`GenerationFailureParser::requiredFile()` rend `null`. Touchés : test d'apworld à l'upload, test candidat, test
tournant (`apworld_preflight.go:99`) et slot preflight (`slot_preflight.go:82,93`). Le webhook de crash, lui, passe
le stderr complet. **Conséquence : l'incident `required_file` de 44.1 ne s'ouvre en pratique pas pour un vrai
DKC3** (les tests de 44.1 n'utilisaient que des enregistrements courts).

**Jeux officiels :** un `Game` sans `apworldHash`, identifié par `archipelagoGameName`
(`Game::isBundledWithAp()`, `Game.php:719`). Ils ne passent ni par le test d'upload ni par le test tournant ;
`ReportDefaultYamlFailure` rend `NotServedHash` pour eux. Seuls le slot preflight et le crash de génération les
voient.

## Critères d'acceptation

### Orchestrateur

1. **Ligne d'échec intacte** : `preflightErrorExcerpt` ne coupe jamais une ligne qui commence par
   `###ARCHILAN-FAILURE###` ou `###ARCHILAN-WARNING###`, et la garde même quand la queue de 2000 caractères
   l'exclurait (elle est toujours la dernière de stderr ; la garder entière, puis tronquer ce qui précède). Test
   avec la vraie ligne DKC3 (`api/tests/Unit/Sessions/GenerationFailureParserTest.php:282`).
2. **Fichiers déclarés d'un apworld** : l'introspection à l'upload et `POST /apworlds/{hash}/introspect` stockent
   aussi `requiredFiles` (voir AC 4) dans `{hash}.types.json` ; `GET /apworlds/{hash}/options` les rend. Un
   `types.json` ancien sans la clé reste lisible (liste vide).
3. **Fichiers déclarés des jeux officiels** : `GET /runtime/required-files` rend
   `{"image": "<id>", "games": {"<nom AP>": [records]}}` pour les worlds livrés avec l'image, calculé une fois par
   image (conteneur jetable, réseau coupé), gardé en mémoire et recalculé quand l'image change.

### Image archipelago

4. Fonction partagée `generation_setup.declared_required_files(world_cls)` : pour chaque groupe de settings du
   world (`_find_settings_groups`), chaque attribut dont la valeur est un `settings.Path` (ou sous-classe) avec
   `required` vrai, un enregistrement `{setting: "<Groupe>.<clé>", file, extension, description?, md5s?}` (même
   forme et même conversion des MD5 que `required_file_record`). Vérifier les attributs `required`, `md5s`,
   `description` dans le `settings.py` d'AP 0.6.8 (`/app/ArchipelagoSrc`). Toute erreur d'un world = liste vide
   pour ce world, jamais d'échec du script.
5. `introspect_options.py` ajoute `"requiredFiles": [...]` à sa sortie pour le world introspecté.
6. Nouveau mode (script `list_required_files.py` ou option de `introspect_options.py`) : charge les worlds
   officiels de l'image et imprime `{nom du jeu: [records]}` pour ceux qui déclarent au moins un fichier.

### API

7. **Stockage sur le jeu** : `Game` gagne `requiredFiles` (JSON, nullable) : liste de
   `{setting, file, extension, description, md5s, neededAtGeneration: bool}`. Méthodes nommées :
   `recordDeclaredRequiredFiles(list)` (remplace les déclarés, **conserve** `neededAtGeneration` des réglages déjà
   constatés) et `markRequiredAtGeneration(RequiredGenerationFile)` (ajoute le réglage s'il manque, avec les
   métadonnées du verdict, et passe le drapeau à vrai). Migration réversible.
8. **Remplissage des déclarés** :
   - apworld du catalogue : partout où l'introspection est enregistrée (`AdminGameLibrary.php:365-366`,
     `PromoteApworldCandidate.php:79-80`, `BackfillGameOptionTypes.php:67`) ;
   - jeux officiels : commande console + tâche planifiée quotidienne qui lit `GET /runtime/required-files` et met
     à jour les jeux par `archipelagoGameName` ;
   - nouvel apworld servi (promotion d'un candidat) : les déclarés suivent le hash servi ; le drapeau
     `neededAtGeneration` d'un réglage toujours déclaré est conservé.
9. **Constat** : chaque verdict qui porte un `requiredFile` appelle `markRequiredAtGeneration` sur le jeu
   concerné : réconciliation des incidents (`ReconcileApworldIncidents`), slot preflight
   (`RunSlotPreflightJobHandler::poll`), crash de génération (`SessionLifecycleManager::recordCrash`, par slot
   fautif quand la ligne nomme le slot, sinon ignoré si le jeu ne peut être déterminé). Idempotent.
10. **Incident** : l'incident `required_file` continue de porter le message lisible (inchangé).
11. **Exposition** :
    - `GET /runs/{runId}/participants/me/game-selection` : `availableGames[*].requiredFiles` et `slots[*].requiredFiles` ;
    - `GET /api/v1/runs/{runId}` (propriétaire seulement) : `generationFiles`, liste dédoublonnée par réglage sur
      tous les jeux de la run, `{setting, description, extension, md5s, neededAtGeneration, games: [noms]}` ;
    - `GET /api/v1/admin/sessions/{id}` et le builder de session admin : même `generationFiles`.
    Ces champs sont des métadonnées publiques d'apworld : aucune donnée de joueur, aucune ROM.
12. **Admin** : la fiche jeu (`AdminGameLibrary` détail) affiche les fichiers déclarés et lesquels sont constatés ;
    un admin peut retirer le drapeau `neededAtGeneration` d'un réglage (faux positif) - action tracée dans les logs.

### Tests et gates

13. Go : `preflightErrorExcerpt` (ligne sentinelle longue gardée entière, au-delà de 2000 caractères aussi) ;
    endpoint `/runtime/required-files` (cache par image). Python : `declared_required_files` (faux module
    `settings` avec `Path` requis / non requis, MD5 en bytes) ; sortie d'`introspect_options` ; mode officiels.
    PHP : entité (`recordDeclared` conserve le constat, `markRequired` idempotent), constat sur les trois chemins,
    sync des officiels (gateway double), payloads exposés. Gates verts des trois dépôts (+ client si touché).
14. **Vérification réelle** : avec l'image locale, DKC3 du catalogue déclare `DK3Settings.rom_file` avec le MD5
    `120abf30...` ; un jeu officiel qui demande une ROM (ALttP ou OoT) apparaît dans `/runtime/required-files` ;
    un slot preflight DKC3 sans fichier marque le réglage `neededAtGeneration`.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Orchestrateur : ligne sentinelle intacte dans `preflightErrorExcerpt` ; tests.
- [x] **Task 2** (AC 4, 5, 6) - Image : `declared_required_files`, sortie d'introspection, mode officiels ; tests.
- [x] **Task 3** (AC 2, 3) - Orchestrateur : `requiredFiles` dans `types.json`, `GET /runtime/required-files`
  avec cache par image ; Swagger.
- [x] **Task 4** (AC 2, 3) - Client PHP (accord de Jean avant publication, voir `packages/CLAUDE.md`) :
  `ApworldOptionTypes`/réponse d'introspection avec `requiredFiles`, `runtime()->requiredFiles()` ; version 1.13.0.
- [x] **Task 5** (AC 7, 8) - API : colonne + migration, méthodes d'entité, remplissage (introspection, commande et
  tâche planifiée des officiels).
- [x] **Task 6** (AC 9, 10) - API : constat sur les trois chemins.
- [x] **Task 7** (AC 11, 12) - API : exposition dans les payloads ; fiche admin (affichage + retrait du drapeau) ;
  front : types et affichage admin seulement (les écrans de lancement sont 44.5).
- [x] **Task 8** (AC 13, 14) - Tests, gates, vérification réelle.

## Dev Notes

### Hors périmètre

- Écrans de lancement, sélection du fichier, MD5 dans le navigateur, envoi : **44.5**.
- Régénérations du suivi exact : 44.4.

### Pièges connus

- **Le sur-ensemble déclaré** : ne jamais rendre un fichier obligatoire sur la seule déclaration (48 jeux en
  demanderaient un pour rien). Seul `neededAtGeneration` rend obligatoire.
- **Ordre des opérations sur le jeu** : une ré-introspection (nouvel apworld) ne doit pas effacer un constat ; un
  réglage qui disparaît des déclarés mais a été constaté reste (le verdict fait foi).
- **Jeux officiels** : seul identifiant = `archipelagoGameName` ; un nom absent de la réponse = aucun fichier.
- **Ligne sentinelle** : elle est la dernière de stderr (`generate_multiworld.py`) ; la garder entière ne doit pas
  faire dépasser le plafond global de façon déraisonnable (la ligne est elle-même bornée par le message de
  1200 caractères côté image).
- **Client PHP** : règle `packages/CLAUDE.md` (boîte noire pendant l'intégration ; gap décrit et accordé) ;
  publication = bump de `"version"` avant le tag (mémoire « Publication d'un client PHP »).
- **Composer sous Windows** : ne jamais filtrer la sortie de `composer install/update` par `| head` (le pipe coupé
  interrompt l'installation et laisse `vendor` à moitié installé). Lancer depuis PowerShell si l'extraction 7-Zip
  échoue.

### Repères

- Orchestrateur : `internal/service/apworld_preflight.go:25-54`, `internal/service/slot_preflight.go:82,93`,
  `internal/docker/client.go:448` (`IntrospectOptions`), `internal/storage/client.go:259`, `internal/api/router.go`.
- Image : `generation_setup.py` (`_find_settings_groups`, `_declared_path`, `required_file_record`,
  `_settings_key_of`), `introspect_options.py:303-313,443`.
- API : `GameSelection/Domain/Entity/Game.php` (`recordOptionTypes` :327, `isBundledWithAp` :719),
  `GameSelection/Application/Command/ReconcileApworldIncidents.php:138-148`,
  `PersonalRuns/Application/Handler/RunSlotPreflightJobHandler.php:85-100`,
  `Sessions/Application/Service/SessionLifecycleManager.php:225-260`,
  `Sessions/Application/Support/RequiredGenerationFile.php`, `GenerationFailureParser.php:111-138`,
  `PersonalRuns/Application/Service/PersonalRunGameSelection.php:83-114,786-797`,
  `PersonalRuns/Application/Service/PersonalRunDrafts.php:473`,
  `Sessions/Application/Service/SessionOrchestrator.php:89-142` (builder),
  `Sessions/Infrastructure/Http/RunnerGateway.php:110` (`fetchOptionTypes`).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- **Orchestrateur** (branche `feature/epic-44-story-3-metadonnees-fichier-requis`, commit 96ed089) :
  `preflightErrorExcerpt` garde entières les lignes `###ARCHILAN-FAILURE###` / `WARNING`, en fin d'extrait, même
  au-delà de la queue de 2000 caractères ; `GET /apworlds/{hash}/required-files` (lu dans `types.json`) ;
  `GET /runtime/required-files` (conteneur jetable, cache par id d'image, un échec n'est pas mis en cache). Swagger
  régénéré. `go build/vet/test` verts.
- **Image** (même branche, commit 0886fc2) : `generation_setup.declared_required_files` (les `FilePath` requis non
  exécutables ; dossiers et `.exe` écartés : affaire du client, le constat les rattrape s'ils servent vraiment à
  générer) ; `requiredFiles` dans la sortie d'`introspect_options.py` ; `generate_multiworld.py
  --list_required_files` (importe une fois les worlds-dossiers de la release : alttp, oot...). pytest 168 verts.
- **Client** (`feature/story-44-3-fichiers-declares`, commit 65bf34f, **non poussé, non tagué**) :
  `apworlds()->getDeclaredFiles()`, `runtime()->getDeclaredFiles()`, `DeclaredFile`, `OfficialDeclaredFiles` ;
  version 1.13.0. PHPUnit 109 verts, PHPStan vert.
- **API** :
  - `Game::$requiredFiles` (colonne `required_files`, migration `Version20261015200000`) ;
    `recordDeclaredRequiredFiles` (garde le constat, même si le world ne le déclare plus), `markRequiredAtGeneration`
    (idempotent, garde description/MD5 déclarés si le verdict n'en a pas), `clearRequiredAtGeneration`.
  - Déclarés : `SyncGameDeclaredFiles::syncAll(bool $reintrospect)` ; commande `app:games:sync-declared-files
    [--reintrospect]` ; tâche planifiée 05:30 (Paris) ; un orchestrateur muet ne touche à rien. Lecture directe à la
    promotion d'un candidat et à la régénération du template.
  - Constat : `RecordGenerationRequiredFile` (parmi les jeux de la génération, celui qui déclare le réglage ; un jeu
    seul l'est d'office ; sinon rien) ; appelé par la réconciliation des incidents, et via
    `RecordGenerationRequiredFileJob` (async) par le slot preflight et `recordCrash`.
  - Exposition : `requiredFiles` (game-selection : slots et jeux proposés) ; `generationFiles` (détail de run,
    propriétaire seulement ; session admin ; builder) via `GenerationFiles::forGames` (un par réglage) ; fiche jeu
    admin `requiredFiles` + `DELETE /api/v1/admin/games/{gameId}/required-files/{setting}/needed`.
  - `composer gates` vert (3069 tests) **avec le client 1.13.0 copié dans vendor** : lock à mettre à jour après
    publication.
- **Front** : type partagé `features/games/required-files.ts` ; section « Fichiers du joueur » sur l'onglet apworld
  de la fiche jeu admin (statut, MD5, « Ne plus l'exiger »). `pnpm gates` vert (1068 tests).
- **Vérification réelle (AC 14)**, image et orchestrateur locaux reconstruits :
  - `/runtime/required-files` : 24 jeux officiels déclarent un fichier (ALttP `ALTTPSettings.rom_file` MD5
    `03a63945...`, OoT 3 MD5, Yoshi's Island...) ;
  - DKC3 ré-introspecté : `DK3Settings.rom_file`, MD5 `120abf30...` ; synchronisation : DKC3 et Yoshi's Island
    remplis sur la base de dev ;
  - test d'apworld DKC3 sans ROM puis `app:apworlds:incidents-reconcile` : incident `required_file` ouvert (il ne
    s'ouvrait pas avant, ligne coupée) et `neededAtGeneration: true` sur DKC3.
- **Écarts** : endpoint dédié `GET /apworlds/{hash}/required-files` plutôt que `GET /apworlds/{hash}/options` (qui
  rend le template, pas `types.json`) ; constat par réglage déclaré plutôt que par slot fautif (la ligne d'échec ne
  nomme pas toujours le slot) ; mise en prod : lancer une fois `app:games:sync-declared-files --reintrospect` (les
  introspections existantes n'ont pas `requiredFiles` : sans cela, seuls les jeux officiels et le constat
  remplissent).
- **Pour 44.5** : le message de l'incident et du joueur dit encore « ArchiLAN ne sait pas encore le fournir » ;
  à reformuler quand l'écran de lancement saura le demander.

### File List

- orchestrateur : `internal/service/apworld_preflight.go` (+ test), `internal/service/declared_files.go` (+ test),
  `internal/api/declared_files_handlers.go` (+ test), `internal/api/router.go`, `internal/api/types.go`,
  `internal/docker/client.go`, `internal/service/service.go`, `docs/*`
- archipelago : `generation_setup.py`, `generate_multiworld.py`, `introspect_options.py`, `tests/test_required_file.py`
- client : `src/RequiredFiles/Response/{DeclaredFile,OfficialDeclaredFiles}.php`, `src/Apworlds/ApworldsClient.php`,
  `src/Runtime/RuntimeClient.php`, `tests/RequiredFiles/DeclaredFilesTest.php`, `composer.json`, `composer.lock`
- api : `migrations/Version20261015200000.php`, `src/GameSelection/Domain/Entity/Game.php`,
  `src/GameSelection/Application/Command/{SyncGameDeclaredFiles,DeclaredFilesSyncReport,RecordGenerationRequiredFile,ReconcileApworldIncidents,PromoteApworldCandidate}.php`,
  `src/GameSelection/Application/{Message/SyncGameDeclaredFilesMessage,Message/RecordGenerationRequiredFileJob,Handler/SyncGameDeclaredFilesHandler,Handler/RecordGenerationRequiredFileJobHandler,Support/GenerationFiles}.php`,
  `src/GameSelection/Application/Service/AdminGameLibrary.php`, `src/GameSelection/Presentation/Command/SyncGameDeclaredFilesCommand.php`,
  `src/GameSelection/Presentation/Controller/AdminGameLibraryController.php`, `src/Schedule.php`, `config/packages/messenger.yaml`,
  `src/Sessions/Application/Port/RunnerGatewayInterface.php`, `src/Sessions/Infrastructure/{Http/RunnerGateway,Double/NullRunnerGateway}.php`,
  `src/Sessions/Application/Support/RequiredGenerationFile.php`, `src/Sessions/Application/Service/{SessionLifecycleManager,SessionOrchestrator}.php`,
  `src/Sessions/Presentation/Controller/AdminSessionController.php`,
  `src/PersonalRuns/Application/{Handler/RunSlotPreflightJobHandler,Service/PersonalRunGameSelection,Service/PersonalRunDrafts}.php`,
  tests : `tests/Unit/GameSelection/GameRequiredFilesTest.php`, `tests/Functional/GameRequiredFilesTest.php` + constructions mises à jour
- frontend : `src/features/games/required-files.ts` (+ test), `src/features/admin/admin-game-required-files.tsx` (+ test),
  `src/features/admin/admin-games-api.ts`, `src/features/admin/admin-game-editor.tsx`
