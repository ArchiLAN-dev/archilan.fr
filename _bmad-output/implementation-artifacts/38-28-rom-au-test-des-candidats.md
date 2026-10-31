# Story 38.28 - Le test d'une nouvelle version accepte la ROM

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds (avec l'epic 44 - fichiers requis)
**Date:** 2026-10-31
**Origine :** Chrono Trigger Rando-Dalton Imperial (SNES), 2026-10-31. Jean importe la v0.0.4 (qui embarque enfin
`ctrando`) : la modale se ferme, la version part en test, et rien ne demande la ROM. Le test échouera sur
`CTRDISettings.rom_file is required`, et aucune relance ne pourra passer.

## Constat

1. **Le rejet n'apprend rien au jeu.** `DecideApworldCandidates` rejette un candidat dont le test échoue faute de
   fichier, sans `RecordGenerationRequiredFile` : seule la réconciliation des versions **en ligne** l'enregistre
   (story 44.3). Un jeu sans version en ligne (Chrono Trigger) ou dont la version actuelle ne lisait pas la ROM ne
   sait donc jamais qu'il faut la demander.
2. **La relance n'accepte pas de fichier.** `POST .../apworld-candidate/retry` relance le test par hash sans fichier :
   il échoue pareil. « Tester avec un YAML » accepte la ROM (44.6) mais ne décide jamais du candidat. Pour un jeu à
   ROM, une nouvelle version ne peut donc passer son test : il ne reste que « Forcer ».
3. **L'import ne prévient pas.** Le test d'un import est lancé par l'orchestrateur au téléversement, sans fichier.

## Décisions

- Un candidat rejeté faute de fichier marque ce fichier comme nécessaire à la génération sur son jeu, comme le fait
  la réconciliation pour une version en ligne.
- « Relancer le test » transmet les fichiers fournis à l'orchestrateur, comme le test de la version en ligne (44.6) :
  confiés pendant la requête, jamais stockés, libérés si le runner refuse le test. Le verdict obtenu avec le fichier
  décide du candidat comme un autre ; il reste valable tant que le hash ne change pas (règle 44.6), donc après la
  mise en ligne aussi.
- Côté admin, « Relancer le test » ouvre une modale avec les fichiers du jeu (ceux constatés et ceux déclarés, comme
  le test YAML d'un candidat), optionnels, vérifiés par MD5 dans le navigateur. Sans fichier connu, la relance reste
  directe.
- **Hors périmètre :** fournir la ROM dès l'import. Le premier test part de l'orchestrateur au téléversement ; il
  faudrait changer son endpoint d'upload. La modale d'import prévient à la place, quand le jeu lit un fichier, que le
  premier test échouera sans lui et qu'il se relance avec.

## Critères d'acceptation

1. Un candidat dont le verdict d'échec nomme un fichier requis est rejeté, et son jeu marque ce fichier
   `neededAtGeneration`.
2. `POST .../apworld-candidate/retry` accepte les fichiers en multipart (même format que `.../apworld-preflight`) ;
   ils sont confiés à l'orchestrateur et leurs ids passés au test ; refusés : 422 `required_file_refused`, rien ne
   change ; runner indisponible : les fichiers sont libérés, 503.
3. Sans fichier, la relance se comporte comme avant.
4. Admin : sur une version rejetée d'un jeu qui lit un fichier, « Relancer le test » ouvre une modale avec la ROM
   (optionnelle) ; le test part avec elle. Sans fichier connu, pas de modale.
5. La modale d'import, quand le jeu lit un fichier, dit que le premier test échouera sans lui.
6. Tests unitaires API et front ; `composer gates` et `pnpm gates` verts.

## Tâches

- [x] `DecideApworldCandidates` : `RecordGenerationRequiredFile` sur un échec qui nomme un fichier.
- [x] `TriageApworldCandidate::retry(gameId, files)` + `ApworldCandidateTriageOutcome::FileRefused` ; contrôleur.
- [x] Front : `retryApworldCandidate(gameId, files)`, modale de relance, avertissement à l'import.
- [x] Tests, gates.

## Dev Agent Record

- API : `DecideApworldCandidates` reçoit `RecordGenerationRequiredFile` et l'appelle sur un verdict `failed` dont
  `GenerationFailureParser::requiredFile` nomme un fichier, avant le rejet. `TriageApworldCandidate::retry(gameId, files)`
  confie les fichiers via `RequiredGenerationFiles::stage`, passe leurs ids à `runApworldPreflight`, les libère si le
  runner refuse ; `ApworldCandidateTriageOutcome::FileRefused` donne un 422 `required_file_refused`. Le contrôleur lit le
  multipart par `RequiredGenerationFilesRequest::read`, comme le test YAML d'un candidat.
- Front : `retryApworldCandidate(gameId, files)` ; `TestGenerationForm` prend désormais ses fichiers et son lancement en
  props et sert aussi la modale « Relancer le test » (fichiers constatés et déclarés). Sans fichier connu, la relance
  reste directe. `ReplaceApworldForm` (exporté pour le test) prévient quand le jeu lit un fichier à la génération.
- Tests : `DecideApworldCandidatesTest`, `TriageApworldCandidateTest`, `AdminApworldCandidateControllerTest`
  (relance avec ROM, ROM refusée), `admin-game-test-files.test.ts`, `admin-game-apworld-tab.test.tsx`.
