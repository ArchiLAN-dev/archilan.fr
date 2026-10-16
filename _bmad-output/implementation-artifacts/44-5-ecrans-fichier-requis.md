# Story 44.5 - Demander le fichier au lancement, le contrôler dans le navigateur

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-16
**Dépend de:** 44.2 (transport, multipart `requiredFiles[<setting>]`), 44.3 (métadonnées `generationFiles`)

## Epic 44 en bref

On ne stocke jamais de ROM. Le lanceur fournit le fichier avant chaque génération ; il est contrôlé (MD5),
transmis au conteneur, puis supprimé.

- 44.1, 44.2, 44.3 (mergées) : détection, transport, métadonnées (`neededAtGeneration`).
- **44.5 (cette story)** : écrans joueur et admin. Découpée de l'ancienne 44.3 le 2026-10-15.
- 44.4 : régénérations du suivi exact sans le fichier.

## Story

En tant que lanceur d'une partie (propriétaire d'une run, ou admin d'une session d'événement),
je veux qu'on me demande, au moment de lancer, le fichier que la génération exige, et qu'on me dise tout de suite
si ce n'est pas le bon,
afin de lancer une partie avec un jeu à ROM sans échec de génération ni patch corrompu.

## Contexte (cartographie du 2026-10-15)

**Ce que l'API fournit déjà (44.2, 44.3)** :
- `GET /api/v1/runs/{runId}` : `generationFiles` (propriétaire seulement) =
  `[{setting, file, extension, description, md5s, neededAtGeneration, games}]`, un par réglage.
- `GET /api/v1/admin/sessions/{id}` et le builder : même `generationFiles`.
- `POST /api/v1/runs/{runId}/start` et `POST /api/v1/admin/sessions/{sessionId}/generate` acceptent un multipart
  `requiredFiles[<setting>]` (4 max, 64 Mo). Erreurs : 422 `validation_failed` (champ `requiredFiles`), 422
  `required_file_refused` (run), 503 `runner_unavailable` (admin), 403 (run : pas propriétaire).
- Fichier expiré entre le dépôt et la génération : la génération échoue avec « Le fichier fourni pour la génération
  n'est plus disponible... Relance la partie en le renvoyant. »
- Type front partagé : `src/features/games/required-files.ts` (`GameRequiredFile`, `asGameRequiredFiles`,
  `requiredFileLabel`).

**Front existant** (Next 16 / React 19 ; lire `frontend/AGENTS.md` et `node_modules/next/dist/docs`) :
- Run : bouton « Démarrer la partie » `personal-run-detail-page.tsx:962-975` ; `handleStart()` (:439-455) appelle
  `apiFetch` **dans le composant** (hors règle AC-API1), lit `error.message` par cast, n'affiche jamais
  `error.details`. Erreurs dans une bannière inline (:936-942).
- Admin : « Générer » / « Générer & Lancer » `admin-session-page.tsx:950-968` (et :1020-1040 en échec) ;
  chaîne validate → generate → launch pilotée par `runChainedAction` (:797-835), **sans corps** (:872-890), et
  **erreurs ignorées** (un non-OK efface seulement `pendingChain`). La chaîne démarre aussi seule :
  `createSession(..., "generate-and-launch")` (:219, :281) et `?autoStart=1` (:436, :783).
- Modèle d'upload le plus proche : import de seed (`importRunSeed`, `personal-runs-api.ts:217-235` ;
  `imported-seed-panel.tsx:80-108` : `<input type="file" className="sr-only">` + bouton stylé).
- Aucune lib de hash : `crypto.subtle` ne fait pas de MD5.
- Tests : Jest 30 en environnement `node`, msw 3, rendu par `renderToStaticMarkup` ; la logique interactive est
  extraite en fonctions pures.

## Décisions

1. **Seuls les fichiers `neededAtGeneration` sont demandés**, et obligatoires. Les fichiers seulement déclarés ne
   sont pas affichés à l'écran de lancement (la plupart ne servent qu'au client).
2. **MD5 dans le navigateur** avec `spark-md5` (pur JS, petit, sans WASM ; lecture par tranches de 2 Mo via
   `File.slice().arrayBuffer()`, sans bloquer l'interface plus d'une tranche). Un fichier dont le MD5 n'est pas dans
   `md5s` est refusé avec le nom attendu ; une liste `md5s` vide = pas de contrôle (on accepte).
3. **Jamais de stockage côté navigateur** : le `File` reste dans l'état React du composant, n'est ni copié en
   `localStorage`/IndexedDB ni gardé après l'envoi ; il est oublié dès que le lancement est accepté ou la page
   quittée.
4. **Participant qui relance une run en veille** : une run dont un jeu exige un fichier ne peut être relancée que par
   son propriétaire (le lanceur fournit le fichier, décision provisoire de l'epic). L'API expose
   `requiresLauncherFiles: bool` à tous les membres ; le bouton d'un participant est alors désactivé avec
   l'explication.

## Critères d'acceptation

### Run perso (joueur)

1. Sur la page de la run (propriétaire, statut `draft` ou `idle`), quand `generationFiles` contient au moins un
   fichier `neededAtGeneration`, un panneau « Fichiers à fournir » apparaît au-dessus du bouton de démarrage :
   pour chacun, le libellé (`requiredFileLabel`), l'extension, les jeux concernés, et un sélecteur de fichier
   (`accept` = l'extension).
2. Après sélection : calcul du MD5 avec une progression ; état « Fichier reconnu » si le MD5 est accepté, sinon
   « Ce n'est pas le bon fichier : <libellé> attendu » (et l'empreinte calculée, pour le support). Un fichier de plus
   de 64 Mo est refusé sans calcul.
3. « Démarrer la partie » reste désactivé tant qu'un fichier exigé manque ou est refusé, avec la raison sous le
   bouton.
4. Le démarrage passe par une nouvelle fonction `startRun(runId, files)` dans `personal-runs-api.ts` (plus
   d'`apiFetch` dans le composant) : JSON vide sans fichier, multipart `requiredFiles[<setting>]` sinon. Résultat
   typé, jamais d'exception.
5. Les erreurs de l'API sont lisibles : le premier message de `error.details.requiredFiles` en 422
   `validation_failed`, le message de `required_file_refused`, et le 403. Le fichier sélectionné est gardé pour
   réessayer.
6. **Participant** : quand `requiresLauncherFiles` est vrai, son bouton de reprise est désactivé avec « Seul
   <propriétaire> peut relancer cette partie : un des jeux demande un fichier au lancement. » (API : nouveau champ
   `requiresLauncherFiles` dans le détail de run, vrai si un jeu de la run a un fichier `neededAtGeneration`).
7. Une génération échouée pour fichier expiré affiche déjà le message de l'API (bannière d'échec existante) ;
   vérifier qu'il est visible et que le panneau redemande le fichier.

### Session d'événement (admin)

8. Page de session admin : quand `generationFiles` contient un fichier exigé, un panneau identique (composant
   partagé) apparaît avant les boutons « Générer » / « Générer & Lancer » ; ces boutons sont désactivés tant que les
   fichiers ne sont pas prêts.
9. La chaîne automatique (`generate-and-launch`, `?autoStart=1`) **s'arrête avant generate** quand un fichier est
   exigé et non fourni : elle valide, puis attend l'admin avec le panneau ; elle reprend generate → launch une fois
   les fichiers prêts et l'admin ayant cliqué.
10. L'appel generate passe par une fonction de `admin-sessions-api.ts` (`generateSession(sessionId, files)`) en
    multipart quand il y a des fichiers ; ses erreurs (422, 503, 409) s'affichent au lieu d'être ignorées.

### Messages

11. `RequiredGenerationFile::message()` (API) ne dit plus « ArchiLAN ne sait pas encore le fournir » : « Ce jeu
    demande le fichier « <libellé> » (<ext>) à la génération. Le lanceur doit le fournir au lancement de la partie. »
    (incident admin, message joueur, notifications). Mettre à jour les tests qui citent l'ancien texte.

### Tests et gates

12. Front : fonctions pures (état d'un fichier : manquant / calcul / reconnu / refusé / trop lourd ; « prêt à
    lancer » ; construction du FormData), MD5 sur un petit `Blob` connu, `startRun` et `generateSession` via msw
    (multipart lu avec `request.formData()`, erreurs 422/403/503), rendu statique du panneau. API : champ
    `requiresLauncherFiles`, nouveau message. `pnpm gates` et `composer gates` verts.
13. **Vérification réelle** (navigateur, base de dev, image et orchestrateur à jour) : run DKC3 → panneau, faux
    fichier refusé, vraie ROM reconnue, démarrage, génération réussie ; puis côté admin une session avec DKC3.
    Noter dans le Dev Agent Record.

## Tasks / Subtasks

- [x] **Task 1** (AC 2, 12) - Dépendance `spark-md5` (+ types), module `src/features/games/required-file-check.ts` :
  MD5 par tranches, états purs, validation taille/extension/MD5 ; tests.
- [x] **Task 2** (AC 1, 2, 3) - Composant partagé `RequiredFilesPanel` (`src/features/games/`) : liste des fichiers
  exigés, sélecteur, progression, statut ; callback `onChange(filesBySetting, ready)`.
- [x] **Task 3** (AC 4, 5, 6, 7) - Run : `startRun` dans `personal-runs-api.ts`, intégration dans
  `personal-run-detail-page.tsx`, erreurs détaillées, participant ; API `requiresLauncherFiles` + type front.
- [x] **Task 4** (AC 8, 9, 10) - Admin : `generateSession` dans `admin-sessions-api.ts`, panneau sur
  `admin-session-page.tsx`, arrêt de la chaîne automatique, erreurs affichées ; type `generationFiles` sur la
  session.
- [x] **Task 5** (AC 11) - API : nouveau message, tests mis à jour.
- [ ] **Task 6** (gates verts ; vérification réelle à faire, voir Dev Agent Record) (AC 12, 13) - Tests, gates, vérification réelle.

## Dev Notes

### Hors périmètre

- Fichiers seulement déclarés (optionnels) : non affichés au lancement.
- Runs hebdo (générées par le planificateur) et tests de config d'un slot : ils échouent toujours avec le message
  lisible.
- Régénérations du suivi exact : 44.4.
- Stockage d'une ROM « pour la prochaine fois » : exclu par principe (droit d'auteur).

### Pièges connus

- **MD5 d'un gros fichier** : ne pas lire 64 Mo d'un coup dans le thread principal ; tranches de 2 Mo, `await`
  entre chaque. Pas d'`ArrayBuffer` gardé après le calcul.
- **Le `File` ne passe pas dans TanStack Query** ni dans une clé de requête : état local du composant uniquement
  (AC-ST2).
- **Chaîne admin** : l'effet `:806-835` relance generate sur le statut `ready` ; il doit lire « fichiers prêts »
  avant, sinon il part sans fichier et l'orchestrateur échoue sur la ROM manquante.
- **`apiFetch` et le 401** : il rejoue la requête avec le même `FormData`, ce qui marche pour un `File`.
- **Pas de cast `as`** sur les réponses : gardes de type dans les fichiers `*-api.ts`.
- **Windows** : `pnpm` est sous `C:\Users\maste\AppData\Local\nvm\v22.14.0` (le Node actif n'en fournit pas) ;
  mettre ce dossier dans le PATH avant `pnpm gates`.

### Repères

- Front : `src/features/personal-runs/personal-run-detail-page.tsx` (:432-455, :694-695, :936-975),
  `personal-runs-api.ts` (:217-278), `types.ts` (:126-174), `imported-seed-panel.tsx`,
  `src/features/admin/admin-session-page.tsx` (:219, :281, :436, :783, :797-890, :950-1040),
  `admin-sessions-api.ts` (:27-53, :148-162), `src/features/games/required-files.ts`,
  `src/lib/apiFetch.ts`, tests modèles `tutorial-image-api.test.ts`, `imported-seed-panel.test.tsx`.
- API : `Sessions/Application/Support/RequiredGenerationFile.php` (`message()`),
  `PersonalRuns/Application/Service/PersonalRunDrafts.php` (`get()`, `generationFiles()`).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- `spark-md5` ^3.0.2 (+ `@types/spark-md5`). Module pur `src/features/games/required-file-check.ts` : MD5 par
  tranches de 2 Mo, états d'un fichier (manquant, vérification, reconnu, refusé, trop lourd), `launchBlocker`,
  `requiredFilesForm` (`requiredFiles[<setting>]`). Type `GenerationFile` + `asGenerationFiles` dans
  `required-files.ts`.
- Composant partagé `required-files-panel.tsx` : hook `useProvidedFiles` (les `File` restent dans l'état du
  composant, remis à zéro après l'envoi) et `RequiredFilesPanel` (un sélecteur par fichier exigé, `accept` =
  extension, statut et empreinte en cas de refus).
- Run : `startRun(runId, files)` dans `personal-runs-api.ts` (l'`apiFetch` inline de la page est retiré ; erreurs
  détaillées via `error.details`) ; composant `PersonalRunStart` à la place du bouton « Démarrer la partie ».
- Admin : `fetchAdminSessionDetail` lit `generationFiles` ; `generateSession(sessionId, files)` (multipart, erreurs
  rendues au lieu d'être ignorées) ; panneau sous la barre d'actions (statuts draft/ready/failed) ; boutons
  Générer / Générer & Lancer / Régénérer désactivés tant qu'un fichier manque ; la chaîne automatique s'arrête au
  statut `ready` si un fichier manque.
- API : message « Le lanceur doit le fournir au lancement de la partie. » (incident, joueur) ; tests mis à jour.
- Gates : `pnpm gates` vert (1081 tests), `composer gates` vert (3069 tests).
- **Écart (AC 6)** : la reprise d'une run en veille passe par `POST /sessions/{id}/restart` (relance depuis la
  sauvegarde, sans génération) : aucun fichier n'y est nécessaire, donc ni champ `requiresLauncherFiles` ni blocage
  du participant.
- **Vérification réelle (AC 13) non faite par l'agent** : en local, pas de worker messenger et API en
  `php -S localhost` (webhooks injoignables), la génération lancée depuis l'écran resterait en « starting ». Le
  transport du fichier jusqu'à une génération réussie est déjà vérifié en 44.2 ; reste à voir l'écran en vrai
  (DKC3 est `neededAtGeneration` sur la base de dev).

### File List

- frontend : `package.json`, `pnpm-lock.yaml`, `src/features/games/{required-file-check.ts,required-file-check.test.ts,required-files.ts,required-files-panel.tsx}`,
  `src/features/personal-runs/{personal-run-start.tsx,personal-run-start.test.tsx,personal-runs-api.ts,types.ts,personal-run-detail-page.tsx}`,
  `src/features/admin/{admin-sessions-api.ts,admin-session-page.tsx,admin-session-generate.test.ts}`
- api : `src/Sessions/Application/Support/RequiredGenerationFile.php`, `tests/Unit/GameSelection/ReconcileApworldIncidentsTest.php`,
  `tests/Unit/Sessions/GenerationFailureParserTest.php`
