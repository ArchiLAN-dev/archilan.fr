# Story 9.57: Importer un YAML dans les éditeurs, vérifier sa structure et le tester

**Status:** review
**Epic:** 9 - Sélection des jeux et YAML
**Date:** 2026-10-19

## Story

En tant que joueur ou admin qui configure un jeu,
je veux importer mon propre fichier YAML dans l'éditeur, voir tout de suite s'il est compatible avec le jeu, et
pouvoir lancer une génération de test,
afin de réutiliser une config préparée ailleurs (un YAML communautaire, celui d'une autre partie, une config
d'hebdo écrite à la main) sans tout ressaisir option par option.

## Contexte

Les trois écrans qui éditent un YAML passent tous par `YamlOptionEditor`
(`frontend/src/features/events/yaml-option-editor.tsx`) :

- le modèle d'hebdo admin (`admin-weekly-template-form.tsx`, mode `onChange`, sauvegarde par le formulaire) ;
- le slot d'une inscription à un événement (`slot-yaml-gate.tsx`, sauvegarde interne
  `PUT /registrations/{id}/slots/{slotId}/yaml`) ;
- le slot d'une partie perso (`personal-run-slot-yaml-page.tsx`, `PUT /runs/{runId}/participants/me/slots/{slotId}/yaml`).

L'éditeur sait télécharger un YAML, mais pas en importer un. Le mode « Avancé » change les widgets (pondérations),
ce n'est pas un éditeur de texte brut. Aucun contrôle ne vérifie qu'un YAML correspond aux options du jeu : ni côté
frontend (seulement poids nuls, bornes des Range et nom du slot), ni côté API à l'enregistrement.

Une génération de test d'un YAML arbitraire existe déjà, mais seulement pour l'admin, contre une version candidate
d'apworld (story 38.14, `StartApworldCandidateYamlTest`). Elle passe par
`RunnerGatewayInterface::startSlotPreflight(yaml, apworldHash, fileIds)` et `getSlotPreflight(jobId)`, qui
acceptent n'importe quel YAML et n'importe quel apworld (null pour un monde du cœur d'Archipelago).

## Acceptance Criteria

1. **Bouton d'import dans tous les éditeurs.** La barre d'outils de `YamlOptionEditor` affiche « Importer un YAML »
   à côté de « Télécharger », dans les trois écrans, dès que l'éditeur est modifiable. Il ouvre un panneau qui accepte
   un fichier `.yaml` / `.yml` (100 Ko au plus) ou un texte collé.
2. **Vérification de structure avant application.** Le contenu est analysé par une fonction pure
   `checkImportedYaml(text, base, { locationNames })` (`src/lib/archipelago-yaml-import.ts`), où `base` est le YAML par
   défaut du jeu analysé avec ses types d'options. Elle renvoie des erreurs bloquantes et des avertissements :
   - erreurs : YAML illisible ; plusieurs documents (`---`) ; racine qui n'est pas un mapping ; `game` absent, ou qui ne
     désigne pas ce jeu (chaîne différente, ou mapping pondéré où ce jeu n'a aucun poids) ; section du jeu absente ou
     qui n'est pas un mapping ; valeur d'option incompatible avec son type connu (Toggle hors booléen / pondération
     booléenne, Choice hors des choix et alias, Range non entier ou hors bornes sans nom spécial valide, pondérations
     toutes à zéro) ; nom de slot invalide (règles de `isValidSlotName`) ;
   - avertissements : option inconnue du jeu (elle sera ignorée par l'éditeur), lieu inconnu dans
     `exclude_locations` / `priority_locations` / `start_location_hints` quand la liste des lieux est connue.
   Le panneau liste les erreurs et avertissements avec le chemin de l'option. Tant qu'il reste une erreur, « Appliquer »
   est désactivé.
3. **Application.** « Appliquer » remplace le contenu de l'éditeur par le YAML importé, fusionné sur le YAML par défaut
   du jeu (les options absentes du fichier gardent leur valeur par défaut). L'éditeur est marqué modifié : rien n'est
   enregistré tant que l'utilisateur ne sauvegarde pas (ou ne valide pas le formulaire d'hebdo). Le nom du slot importé
   est conservé s'il est valide.
4. **Génération de test.** Un bouton « Tester la génération » (barre d'outils, et dans le panneau d'import une fois la
   structure valide) lance une vraie génération solo du YAML affiché contre l'apworld actuel du jeu, puis affiche
   « en cours », « réussie » ou « échouée » avec l'erreur résumée (`GenerationFailureParser::summarize`). Le test ne
   modifie rien et ne bloque rien.
5. **API du test.**
   - `POST /api/v1/games/{gameId}/yaml-test`, utilisateur connecté, corps `{yaml}` (100 Ko au plus) → 202 `{ticket}` ;
   - `GET /api/v1/games/{gameId}/yaml-test/{ticket}` → `{status: pending|running|passed|failed, error}` ; le ticket
     lie le job au joueur et au jeu (HMAC) : seul l'utilisateur qui a lancé le test peut le lire (sinon 404) ;
   - jeu inconnu ou sans apworld configuré → 404 ; jeu qui exige un fichier (une ROM, epic 44) → 409
     `required_file`, avec un message qui renvoie vers le test du slot ; runner indisponible → 503 ;
   - au plus 5 tests par utilisateur et par 10 minutes → 429 au-delà.
6. **Tests.** Tests unitaires de `checkImportedYaml` (chaque erreur, chaque avertissement, un YAML valide complet, et
   l'application sur les valeurs par défaut) ; test de rendu de l'éditeur (boutons selon le contexte) ; tests API du
   démarrage, de la lecture (propriétaire, autre utilisateur, autre jeu), du jeu à fichier requis, du plafond.

## Tasks / Subtasks

- [x] Frontend
  - [x] `checkImportedYaml` + tests (AC 2)
  - [x] Panneau d'import (fichier ou texte) dans `YamlOptionEditor`, application et état modifié (AC 1, 3)
  - [x] Bouton et suivi de la génération de test, client API (AC 4)
  - [x] Passer `gameId` à l'éditeur depuis les trois écrans
- [x] API
  - [x] Commande `StartGameYamlTest` (contrôles, plafond, appel au runner) et requête de résultat liée à l'utilisateur
  - [x] Contrôleur `GameYamlTestController` (AC 5)
  - [x] Tests
- [x] Gates verts des deux côtés

## Dev Notes

- Le plafond vit dans le cache applicatif (`cache.app`), pas en base : une fenêtre fixe de 10 minutes par joueur. Le
  propriétaire du job n'est stocké nulle part : le ticket rendu au joueur est `jobId.hmac(joueur, jeu, jobId)`, signé
  avec le secret de l'application. L'orchestrateur ne garde ses résultats que 30 minutes de toute façon.
- Les jeux à fichier requis restent testables par le test du slot (story 44.7), qui sait joindre la ROM : ce test-ci
  n'accepte pas de fichier, pour ne pas faire circuler de ROM hors d'un slot.
- La vérification de structure est un garde-fou, pas une garantie : seule la génération de test fait foi (un
  `start_hints` mal orthographié ou une combinaison d'options impossible passe la structure).

## Dev Agent Record

### Implementation Notes

- Un YAML écrit à la main utilise des scalaires (`Goal: hollowknight`, `StartingGeo: 300`, `RandomizeSkills: true`).
  `mergePlayerValues` les aurait lus comme du texte et ignorés en silence (types différents). `checkImportedYaml`
  convertit donc chaque valeur au modèle de l'option sur laquelle elle tombe (interrupteur, choix insensible à la casse,
  Range bornée ou valeur nommée ou alias aléatoire, liste, dictionnaire, plando, item links) et applique le résultat
  sur les valeurs par défaut du jeu, pas sur les modifications en cours : le fichier importé décrit toute la config.
- Les options communes (`start_hints`...) sont lues dans la section du jeu ou au premier niveau, comme Archipelago.
- Le bouton « Tester la génération » est aussi dans la barre de l'éditeur, pour tester la config affichée sans import.
- Validé sur le YAML Hollow Knight de l'hebdo : 5 seeds générées dans l'image archipelago 0.6.8 avant cette story.

### File List

- `frontend/src/lib/archipelago-yaml-import.ts` (nouveau), `frontend/src/lib/archipelago-yaml-import.test.ts` (nouveau)
- `frontend/src/lib/archipelago-yaml.ts` (exports `STANDARD_KEYS`, `parsePlandoEntries`, `parseItemLinkEntries`,
  `dumpFreeformValue`)
- `frontend/src/features/events/yaml-import-dialog.tsx` (nouveau)
- `frontend/src/features/events/yaml-option-editor.tsx`, `yaml-option-editor-import.test.tsx` (nouveau)
- `frontend/src/features/games/game-yaml-test-api.ts`, `game-yaml-test.tsx` (nouveaux)
- `frontend/src/features/admin/admin-weekly-template-form.tsx`, `frontend/src/features/events/slot-yaml-gate.tsx`,
  `frontend/src/features/personal-runs/personal-run-slot-yaml-page.tsx` (passent `gameId`)
- `api/src/GameSelection/Application/Command/StartGameYamlTest.php`, `GameYamlTestStart.php`,
  `GameYamlTestOutcome.php` (nouveaux)
- `api/src/GameSelection/Application/Support/GameYamlTestTicket.php` (nouveau)
- `api/src/GameSelection/Application/Query/GameYamlTestQuery.php` (nouveau)
- `api/src/GameSelection/Presentation/Controller/GameYamlTestController.php` (nouveau)
- `api/tests/Functional/GameYamlTestControllerTest.php`,
  `api/tests/Unit/GameSelection/Application/Command/StartGameYamlTestTest.php` (nouveaux)
