# Story 47.5 - Le serveur média d'un événement et la clé de publication

**Status:** review
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-5-serveur-media-et-cle` (depuis la branche du spike 47.4, PR empilée sur #1091)
**Dépend de:** 47.4 (endpoint d'essai remplacé ici).

## Story

En tant qu'orga,
je veux indiquer le serveur média de la LAN sur l'événement, et que chaque inscrit confirmé ait sa propre clé
pour y publier,
afin que seuls nos joueurs, et seulement pendant l'événement, puissent envoyer leur écran à la régie.

## Contexte

- Spike 47.4 : MediaMTX envoie à `POST /api/v1/media-auth` `{ip, user, password, token, action, path, protocol,
  id, query, userAgent}` ; la clé arrive dans `token` (WHIP) ou `password` (SRT) et ne doit jamais contenir « : ».
  Chemins `p-*` seulement. L'endpoint d'essai (`MediaPublishSpikeGate`, `MEDIA_AUTH_SPIKE_KEY`) est remplacé ici.
- Refus de diffusion : `Registration::hasBroadcastConsent()` (47.1). Bannissement : `User::getBannedAt()`.
- Inscription confirmée : `isReserved()` et `submittedAt` non nul.
- Modèles de secret dérivé : `Wallet/Application/Support/BlindTestLink.php` (HMAC).

## Décisions

1. **Serveur média** : `Event::mediaServerHost` (nom d'hôte seul, minuscules, 253 caractères au plus, au moins un
   point, sans schéma ni port ; vide = fonction absente). Méthode `pointMediaServer(?string, now)`, erreur
   `event_media_server_invalid`. Champ « Serveur média local » dans le formulaire admin de l'événement (bloc
   « Diffusion locale »), avec l'aide « ex. media.lan.archilan.fr, voir ops/lan-media ». Ports fixes : 8889 (WHIP),
   8890 (SRT), ceux de `ops/lan-media/mediamtx.yml`.
2. **Chemin** d'un joueur : `p-` + 20 caractères hexadécimaux de `HMAC(APP_SECRET, "archilan-media-path|" + id)`.
   Opaque (ni pseudo ni id d'inscription), stable.
3. **Clé** : 32 caractères hexadécimaux de `HMAC(APP_SECRET, "archilan-media-key|" + eventId + "|" + id + "|" +
   version)`. Jamais stockée. `Registration::mediaKeyVersion` (entier, 0 par défaut) ; `regenerateMediaKey(now)`
   l'incrémente (inscription réservée seulement).
4. **Fenêtre de publication** : de 24 h avant le début à la fin de l'événement, événement `published` ou
   `in-progress` avec un serveur média.
5. **`POST /api/v1/media-auth`** (appelé par MediaMTX, public) : 200 seulement si action `publish`, chemin `p-` +
   20 hex, un inscrit d'un événement dans sa fenêtre a ce chemin, inscription confirmée, diffusion non refusée,
   compte présent et non banni, clé égale (`hash_equals`) à celle de `token` ou de `password`. Sinon 401 sans
   détail. Chaque décision est journalisée avec sa raison, sans secret. Pas de limitation de débit : la clé fait
   128 bits et MediaMTX appelle depuis une seule IP pour toute la salle.
6. **Lecture par le joueur** (pour la carte 47.7) : `GET /api/v1/registrations/{id}/media-publish` (propriétaire
   seulement, 403 sinon, 404 inscription absente ou annulée) :
   - `available: false` + `reason` (`no_media_server`, `not_confirmed`, `broadcast_refused`, `outside_window`,
     `event_closed`) sans clé ;
   - `available: true` + `path`, `key`, `whipUrl` (`https://<hôte>:8889/<chemin>/whip`), `srtUrl`
     (`srt://<hôte>:8890?streamid=publish:<chemin>:archilan:<clé>&pkt_size=1316`), `opensAt`, `closesAt`.
   `POST .../media-publish/regenerate` : nouvelle clé (version + 1), même réponse.
7. Le secret de l'agent de régie passe en 47.6 (il ne sert qu'à l'agent).

## Critères d'acceptation

1. L'admin renseigne, modifie ou vide le serveur média ; une valeur invalide est refusée (422) ; un enregistrement
   sans le champ garde la valeur.
2. Un inscrit confirmé, dans la fenêtre, publie avec sa clé en WHIP (`token`) ou SRT (`password`) : 200.
3. Refus 401 : mauvaise clé, ancienne clé après régénération, action `read`, chemin inconnu, hors fenêtre,
   événement sans serveur média ou brouillon, inscription non confirmée ou annulée, diffusion refusée, compte banni.
4. Le joueur lit sa clé et ses adresses, ou la raison de leur absence ; il régénère sa clé ; un autre membre reçoit
   403.
5. L'endpoint d'essai et `MEDIA_AUTH_SPIKE_KEY` sont retirés ; `ops/lan-media/README.md` décrit la vraie clé.

## Tests et gates

6. Unitaires : chemin et clé (stables, différents par inscription, la clé change avec la version, sans « : »),
   nom d'hôte (valides, invalides), `regenerateMediaKey`.
7. Fonctionnels : `media-auth` (chaque cas d'acceptation et de refus), lecture et régénération par le joueur,
   champ admin.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `Event::mediaServerHost`, `Registration::mediaKeyVersion`, migration, brouillons admin,
  vue admin, formulaire.
- [x] **Task 2** (AC 2, 3) - `MediaPublishCredentials`, autorisation, `media-auth` réel, retrait de l'essai.
- [x] **Task 3** (AC 4) - Lecture et régénération par le joueur.
- [x] **Task 4** (AC 5-8) - README, tests, gates.

## Dev Notes

- Pas de `date()` / `time()` : `ClockInterface`.
- La clé dépend d'`APP_SECRET` : le changer invalide toutes les clés (à dire dans le README).

## Dev Agent Record

### Completion Notes

- Domaine : `Event::pointMediaServer` / `getMediaServerHost` / `isValidMediaServerHost` (motif
  `MEDIA_SERVER_HOST_PATTERN`, minuscules, au moins un point, sans schéma ni port) ;
  `Registration::regenerateMediaKey` / `getMediaKeyVersion`. Migration `Version20261111100003`.
- Admin : `mediaServerHost` dans les brouillons (gardé si absent, vidé si vide, 422 si invalide), dans
  `AdminEventView`, et bloc « Diffusion locale » du formulaire.
- Streaming :
  - `MediaPublishCredentials` : chemin `p-` + 20 hex, clé 32 hex, dérivés d'`APP_SECRET`, adresses WHIP et SRT ;
  - `MediaPublishEligibility` : fenêtre de 24 h avant le début à la fin, et raisons de refus ;
  - `MediaPublishAuthorization` : le vrai `media-auth`. Il cherche le chemin parmi les inscrits des événements
    ouverts, puis journalise la décision et sa raison (`streaming.media_auth`), sans clé ;
  - `MediaPublishAccess` + `MediaPublishController` : `GET /registrations/{id}/media-publish` et `POST
    .../regenerate`.
- Retrait du spike : `MediaPublishSpikeGate`, `MEDIA_AUTH_SPIKE_KEY` et leurs tests. README de `ops/lan-media`
  mis à jour.
- Les tests fonctionnels ont tourné en local (Postgres de nouveau disponible) : suite complète en ParaTest sur une
  base isolée, 3871 tests verts.

### File List

- api/migrations/Version20261111100003.php (nouveau)
- api/src/Events/Domain/Entity/Event.php
- api/src/Events/Application/Service/AdminEventDrafts.php
- api/src/Events/Application/Query/AdminEventView.php
- api/src/Registrations/Domain/Entity/Registration.php
- api/src/Streaming/Application/Support/MediaPublishCredentials.php (nouveau)
- api/src/Streaming/Application/Support/MediaPublishEligibility.php (nouveau)
- api/src/Streaming/Application/Service/MediaPublishAuthorization.php (nouveau)
- api/src/Streaming/Application/Service/MediaPublishAccess.php (nouveau)
- api/src/Streaming/Application/Service/MediaPublishSpikeGate.php (supprimé)
- api/src/Streaming/Presentation/Controller/MediaAuthController.php
- api/src/Streaming/Presentation/Controller/MediaPublishController.php (nouveau)
- api/config/services.yaml, api/.env, api/.env.test (retrait de la clé d'essai)
- api/tests/Unit/Streaming/MediaPublishCredentialsTest.php (nouveau)
- api/tests/Unit/Streaming/MediaPublishSpikeGateTest.php, api/tests/Functional/MediaAuthSpikeTest.php (supprimés)
- api/tests/Functional/MediaPublishTest.php (nouveau)
- frontend/src/features/admin/admin-event-form.tsx, admin-events-api.ts, admin-event-form-practical-info.test.tsx
- ops/lan-media/README.md, compose.yml, mediamtx.yml
