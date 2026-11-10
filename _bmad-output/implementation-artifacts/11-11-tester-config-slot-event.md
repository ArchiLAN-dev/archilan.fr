# Story 11.11 - Tester la config d'un slot depuis une session d'événement

**Status:** review
**Branche:** `feature/epic-11-story-11-tester-config-slot-event` (depuis `develop`)
**Epic:** 11 - Session Management UX/UI Overhaul (`_bmad-output/planning-artifacts/epics/epic-11-session-management-uxui-overhaul.md`)
**Date:** 2026-11-10
**Dépend de:** 9.42 (« Tester ma config » d'une partie perso), 38.14 (test YAML admin d'un candidat), 44.7 (ROM jointe au test).

## Story

En tant qu'admin d'un événement,
je veux tester seule la config de chaque slot d'une session d'événement, comme le « Tester ma config » des parties
privées,
afin de trouver le ou les YAML qui font échouer la génération sans relancer toute la session à l'aveugle.

## Contexte

Demande de Jean le 2026-11-10 sur la session de l'ArchiLAN #4 (116 slots) : la génération échoue sur des YAML
invalides (une valeur d'option refusée par l'apworld Lego Star Wars, un second slot dans la même erreur). Le message
de génération ne cite que les premiers fautifs ; il faut régénérer pour découvrir les suivants.

Côté partie privée, chaque slot a son test solo (story 9.42) : l'orchestrateur génère le YAML seul, avec une seed,
contre l'apworld (`RunnerGatewayInterface::startSlotPreflight`). L'admin d'un candidat apworld a le même test, avec
ROM jointe (stories 38.14, 44.6). Rien de tel pour un slot d'événement.

## Décisions

- Le test porte sur le YAML **enregistré sur l'inscription** (celui que la génération lit, ou le YAML par défaut du
  jeu si le joueur n'a rien enregistré) et sur l'apworld **servi par le jeu**, exactement comme la génération d'une
  session d'événement (`SessionOrchestrator::enrichSlotsForValidation`). Un joueur qui corrige son YAML est retesté
  tel quel, sans recréer la session.
- Rien n'est enregistré : le verdict vit dans la page (l'orchestrateur garde un résultat 30 minutes). Pas de quota
  pour l'admin ; le front limite les tests simultanés.
- Un jeu qui lit un fichier à la génération (une ROM) n'est pas lancé par « Tout tester » : il est marqué « fichier
  requis » et son bouton ouvre le choix du fichier (même panneau que la story 44.7). Le fichier sert à ce test
  seulement.
- Un échec de ce test n'ouvre pas d'incident apworld : c'est l'admin qui teste un YAML de joueur, pas un verdict sur
  l'apworld.

## Critères d'acceptation

1. Sur la page admin d'une session d'événement, le tableau des slots a une colonne « Config » : un bouton « Tester »
   par slot, puis le verdict (en cours, config testée, échec avec l'erreur résumée, fichier requis).
2. Un bouton « Tester toutes les configs » lance les slots de la session, 3 à la fois au plus, en sautant ceux qui
   demandent un fichier ; un résumé donne le nombre de réussites, d'échecs et de slots qui attendent un fichier.
   Les slots en échec remontent sous le résumé, avec leur nom et l'erreur.
3. `POST /api/v1/admin/events/{eventId}/registrations/{registrationId}/slots/{slotId}/yaml-test` (admin seulement,
   JSON vide ou multipart `requiredFiles[<setting>]`) lance le test et renvoie `202 {data: {jobId}}`.
   - 404 si l'inscription n'est pas de cet événement, si le slot ou le jeu n'existe pas, ou si le jeu n'a pas
     d'apworld prêt ;
   - 422 si le slot n'a aucun YAML (ni enregistré ni par défaut) ;
   - 409 `required_file` avec `details.requiredFiles` quand le jeu lit un fichier et qu'aucun n'est joint ;
   - 422 `required_file_refused` si le fichier n'a pas pu être transmis, 503 si le runner est indisponible.
4. `GET /api/v1/admin/events/{eventId}/yaml-tests/{jobId}` (admin seulement) renvoie `{data: {status, error}}`
   (erreur résumée par `GenerationFailureParser`), 404 pour un test inconnu ou expiré.
5. Tests : API fonctionnels (lancement, 404, 409 fichier requis, 403 non-admin, lecture du résultat) ; front
   (file d'attente limitée, saut des slots à fichier, résumé).

## Tâches

- [x] API : `Sessions\Application\Command\StartEventSlotYamlTest` (+ `EventSlotYamlTestStart`,
      `EventSlotYamlTestOutcome`), contrôleur `AdminEventSlotYamlTestController`.
- [x] Front : `features/admin/event-slot-yaml-test-api.ts`, `event-slot-yaml-tests.ts` (logique pure de la file),
      `event-slot-yaml-tests.tsx` (colonne, bouton global, résumé, dialogue de fichier) branchés dans
      `admin-session-page.tsx`.
- [x] Tests API et front.

## Dev Notes

- Le test lui-même : `RunnerGatewayInterface::startSlotPreflight($yaml, $apworldHash, $fileIds)` puis
  `getSlotPreflight($jobId)` via `GameSelection\Application\Query\YamlTestResultQuery` (déjà utilisé par 38.14 et
  9.57).
- Fichiers : `RequiredGenerationFilesRequest::read`, `RequiredGenerationFiles::stage/release`, comme 38.14.
