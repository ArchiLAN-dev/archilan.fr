# Story 47.6 - L'agent de la régie et l'état des flux

**Status:** review
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-6-agent-regie` (depuis la branche de la 47.5, PR empilée sur #1092)
**Dépend de:** 47.5 (serveur média de l'événement, chemin dérivé de chaque inscrit).

## Story

En tant qu'orga,
je veux que le site sache quels joueurs envoient leur flux au serveur média de la LAN,
afin que l'écran régie (47.8), la carte du joueur (47.7) et le suivi admin (47.9) n'affichent que des flux réels.

## Contexte

- MediaMTX n'est joignable que sur la LAN ; le site ne peut pas l'interroger. Un petit agent, sur la machine de
  régie, lit l'API locale de MediaMTX (`http://127.0.0.1:9997/v3/paths/list`) et pousse la liste au site.
- Spike 47.4 : lire `online` / `onlineTime` (`ready` est déprécié en 1.21) ; un flux coupé sort aussitôt de la
  liste.
- Signature déjà pratiquée : `Wallet/Application/Support/BlindTestLink::verifyRequest` (`{timestamp}.{body}`,
  en-têtes `X-Archilan-Timestamp` et `X-Archilan-Signature`).

## Décisions

1. **Secret de l'agent** : 64 caractères hexadécimaux de `HMAC(APP_SECRET, "archilan-media-agent|" + eventId)`,
   jamais stocké (comme les clés de 47.5). Pas de rotation dans cette story : changer `APP_SECRET` les renouvelle
   tous.
2. **`GET /api/v1/admin/events/{id}/media-agent`** (admin) : `{eventId, secret}`. Le formulaire admin (bloc
   « Diffusion locale ») propose « Configuration de l'agent », qui affiche les lignes à coller dans
   `ops/lan-media/.env`.
3. **`PUT /api/v1/events/{id}/local-streams`** (appelé par l'agent) : corps `{"streams": [{"path", "onlineSince"}]}`
   (200 entrées au plus), signé `HMAC(secret, timestamp + "." + corps)`, horodatage à 120 s près. 401 si la
   signature est fausse ou périmée, 404 si l'événement n'existe pas ou n'a pas de serveur média, 422 si le corps
   est invalide. Réponse `{live, ignored}` : les chemins inconnus de l'événement sont ignorés.
4. **État** (contexte Streaming) :
   - `LocalMediaServer` (une ligne par événement) : `reportedAt`, l'heure du dernier envoi de l'agent ;
   - `LocalStream` (une ligne par inscrit déjà vu) : chemin, `onlineSince` (null = coupé), `lastSeenAt`.
   Chaque envoi remplace l'état : un chemin présent est en ligne (`onlineSince` gardé s'il l'était déjà, sinon
   `onlineTime` de MediaMTX, sinon maintenant), un chemin absent passe coupé en gardant `lastSeenAt` (« coupé il y
   a X min » en 47.9).
5. **Péremption** : un flux est en direct si `onlineSince` est non nul et `lastSeenAt` date de 30 s au plus. Sans
   envoi de l'agent pendant 30 s, plus rien n'est en direct.
6. **Agent** : `ops/lan-media/agent.py` (Python, bibliothèque standard seule), service `agent` du compose (image
   `python:3.12-alpine`). Toutes les 10 s : lit `/v3/paths/list`, garde les chemins `p-*` avec `online` vrai,
   signe et envoie. Erreurs journalisées, jamais fatales.

## Critères d'acceptation

1. L'agent signé correctement remplace l'état ; les chemins inconnus sont ignorés et comptés.
2. Signature fausse, absente ou périmée : 401 ; événement inconnu ou sans serveur média : 404 ; corps invalide : 422.
3. Un flux reste « depuis » la même heure d'un envoi à l'autre ; absent d'un envoi, il est coupé ; sans envoi
   pendant 30 s, il n'est plus en direct.
4. L'admin lit le secret de l'agent ; un non-admin reçoit 403.
5. `ops/lan-media` : agent, service compose, `.env.example` et README à jour.

## Tests et gates

6. Unitaires : `LocalStream` (en ligne, coupé, péremption), secret de l'agent.
7. Fonctionnels : signature (fausse, périmée), remplacement complet, chemin inconnu, péremption, endpoint admin.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Entités, migration, dépôt, commande `ReportLocalStreams`, lecture de l'état.
- [x] **Task 2** (AC 2, 4) - Contrôleurs agent et admin, secret.
- [x] **Task 3** (AC 5) - Agent, compose, README, bloc admin.
- [x] **Task 4** (AC 6-8) - Tests, gates.

## Dev Agent Record

### Completion Notes

- Secret de l'agent et vérification de la signature dans `MediaPublishCredentials` (`agentSecretFor`,
  `verifyAgentRequest`, 120 s d'écart au plus).
- Premières entités du contexte Streaming : `LocalStream` et `LocalMediaServer`. Le mapping Doctrine de Streaming
  pointe désormais sur `Domain/Entity`, comme les autres contextes. Migration `Version20261111100004`.
- `ReportLocalStreams` remplace l'état en une écriture et compte les chemins inconnus. `LocalStreamsState` lit
  l'état, avec la péremption à 30 s : il servira en 47.7 à 47.9 (rien ne l'injecte encore, le test le construit à
  la main).
- `LocalStreamsController` : `PUT /api/v1/events/{id}/local-streams` et `GET /api/v1/admin/events/{id}/media-agent`.
- `ops/lan-media/agent.py`, service `agent` du compose, `.env.example`, README (installation, journal de l'agent,
  essai local).
- Admin : bouton « Configuration de l'agent » dans le bloc « Diffusion locale » ; il ne paraît qu'une fois le
  serveur média enregistré, et le secret n'est chargé qu'au clic.
- Essai réel en local : MediaMTX 1.21.3 sous Docker, une mire ffmpeg sur le chemin d'un inscrit de la démo et une
  sur un chemin inconnu, l'agent contre `php -S`. Résultat : « 1 en direct, 1 ignorés », état écrit en base ;
  mauvais secret, 401 journalisé ; MediaMTX injoignable, erreur journalisée sans arrêt. Données de démo remises en
  l'état.
- Gates : suite API complète en ParaTest (3881 tests), PHPStan, cs-fixer, DDD, rector ; front typecheck, lint,
  1648 tests, build.

### File List

- api/config/packages/doctrine.yaml, api/config/services.yaml
- api/migrations/Version20261111100004.php (nouveau)
- api/src/Streaming/Domain/Entity/LocalStream.php, LocalMediaServer.php (nouveaux)
- api/src/Streaming/Domain/Repository/LocalStreamRepositoryInterface.php (nouveau)
- api/src/Streaming/Infrastructure/Doctrine/DoctrineLocalStreamRepository.php (nouveau)
- api/src/Streaming/Application/Command/ReportLocalStreams.php, LocalStreamsReport.php (nouveaux)
- api/src/Streaming/Application/Exception/MediaAgentSignatureException.php (nouveau)
- api/src/Streaming/Application/Service/LocalStreamsState.php, MediaAgentAccess.php (nouveaux)
- api/src/Streaming/Application/Support/MediaPublishCredentials.php
- api/src/Streaming/Presentation/Controller/LocalStreamsController.php (nouveau)
- api/tests/Unit/Streaming/LocalStreamTest.php (nouveau), MediaPublishCredentialsTest.php
- api/tests/Functional/LocalStreamsReportTest.php (nouveau)
- frontend/src/features/admin/media-agent-config.tsx (nouveau), admin-event-form.tsx, admin-events-api.ts,
  admin-event-form-practical-info.test.tsx
- ops/lan-media/agent.py (nouveau), compose.yml, .env.example, README.md
