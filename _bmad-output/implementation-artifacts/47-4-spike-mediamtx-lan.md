# Story 47.4 - Spike : MediaMTX sur la LAN, de la clé à l'écran régie

**Status:** in-progress (essais sur la LAN à faire : questions 2, 3 et 5)
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-4-spike-mediamtx-lan` (depuis `develop`)
**Dépend de:** rien. Bloque 47.5 à 47.9.

## Story

En tant que développeur,
je veux faire tourner MediaMTX sur une LAN de test, publier depuis OBS avec une clé vérifiée par l'API et lire le
flux dans la source navigateur d'OBS depuis une page HTTPS d'archilan.fr,
afin de savoir si la diffusion locale est faisable telle que l'epic la décrit, et sous quelle forme.

## Contexte (cartographie du 2026-11-11)

- Écran régie : `frontend/src/app/(overlay)/o/streams/page.tsx` (47.2), lecture `GET /api/v1/events/{eventId}/broadcast-streams`
  (`api/src/Streaming/Presentation/Controller/ParticipantStreamsController.php`, 47.1). Lives Twitch seulement.
- Refus de diffusion : `registration.broadcast_consent` (47.1), à revérifier à chaque publication.
- Modèle de webhook signé : `api/src/Sessions/Presentation/Controller/OrchestratorWebhookController.php` (HMAC) ;
  modèles de jeton calculé : `Wallet/Application/Support/BlindTestLink.php`, `Identity/Application/Support/ModerationContactPass.php`.
- MediaMTX (à confirmer sur la version épinglée par le spike) :
  - authentification déléguée : `authMethod: http`, `authHTTPAddress`, requête JSON avec `user`, `password`,
    `token`, `ip`, `action` (`publish`, `read`, `playback`, `api`...), `path`, `protocol`, `query` ;
  - WebRTC sur le port 8889 : publication `https://<hôte>:8889/<chemin>/whip`, lecture `.../<chemin>/whep` ;
  - SRT sur le port 8890 : `srt://<hôte>:8890?streamid=publish:<chemin>:<user>:<clé>` ;
  - API de contrôle sur le port 9997 : `GET /v3/paths/list` (nom, `ready`, `readyTime`, pistes, lecteurs).

## Questions à trancher

1. **Jeton WHIP.** OBS envoie la clé en `Authorization: Bearer <clé>`. MediaMTX la transmet-il à `authHTTPAddress`
   (champ `token` ou `password`) ? Sinon, quelle forme marche (clé dans l'URL `?token=`, `user:clé`) et OBS
   l'accepte-t-il ?
2. **Lecture depuis une page HTTPS publique.** La source navigateur d'OBS (CEF, version de l'OBS actuel) qui charge
   `https://archilan.fr/o/streams` peut-elle lire `https://media.lan.archilan.fr:8889/<chemin>/whep` (IP privée) ?
   Noter l'effet de Private Network Access / Local Network Access (demande d'autorisation, en-tête
   `Access-Control-Allow-Private-Network`, MediaMTX sait-il le renvoyer ?), dans OBS et dans Chrome à jour.
3. **Certificat.** Procédure Let's Encrypt DNS-01 pour un nom qui pointe vers une IP privée (fournisseur DNS
   d'archilan.fr, outil : lego, certbot ou acme.sh), durée, renouvellement, et ce qui se passe si le DNS de la salle
   refuse une réponse en IP privée (protection DNS rebinding de certaines box).
4. **Lecture WebRTC des flux SRT.** H264 avec B-frames et son AAC : l'image passe-t-elle en WHEP ? Réglages minimum
   à imposer côté joueur. WHIP depuis OBS (Opus, sans B-frames) : rien à régler ?
5. **Charge.** Sur une machine modeste (mini PC ou portable de régie), CPU, mémoire et débit avec 6 puis 9 flux en
   1080p60 à 6000 kbit/s, et charge de la source navigateur d'OBS qui affiche 4 puis 9 lecteurs WHEP. Latence
   bout en bout mesurée (chrono à l'écran).
6. **Agent.** Forme exacte de `GET /v3/paths/list` (et pagination) ; un flux qui coupe disparaît-il tout de suite ?
   Ce dont l'agent a besoin pour relier un chemin à une inscription.
7. **Internet coupé.** Comportement de MediaMTX quand `authHTTPAddress` ne répond pas (délai, refus) : un flux déjà
   ouvert continue-t-il ? Une lecture WHEP demande-t-elle aussi l'authentification (`read`), et faut-il l'exclure
   (`authHTTPExclude`) pour que la régie lise sans dépendre d'Internet ?

## Critères d'acceptation

1. Les réponses aux questions 1 à 7 sont consignées dans le Dev Agent Record, avec la version de MediaMTX, d'OBS et
   de Chrome, et les messages ou codes exacts observés.
2. `ops/lan-media/` contient de quoi refaire l'essai : `mediamtx.yml` commenté (authentification HTTP vers l'API,
   ports, chemins `p-*` en publication seule avec authentification), `compose.yml` (image MediaMTX épinglée), et un
   `README.md` en français : installation sur la machine de régie, certificat DNS-01, ports à ouvrir sur la LAN,
   réglages OBS du joueur (WHIP et SRT), vérification rapide.
3. Un endpoint d'essai `POST /api/v1/media-auth` derrière une variable (désactivé par défaut) accepte une clé fixe
   de test : il prouve la chaîne MediaMTX -> API sans le modèle de 47.5. Il est retiré ou remplacé par 47.5.
4. Une page d'essai (ou un paramètre caché de `/o/streams`) lit un chemin WHEP donné, pour mesurer la question 2
   sans toucher l'écran régie.
5. Essai de bout en bout consigné : OBS joueur en WHIP avec la clé, refus avec une mauvaise clé, lecture dans la
   source navigateur d'OBS de la régie depuis `https://` (local ou préprod), coupure et reprise du flux.
6. Recommandation écrite : protocole conseillé, forme du jeton, besoin ou non d'une autorisation `read`, et mise à
   jour de 47.5 à 47.9 dans l'epic si une décision change. Si la question 2 bloque (lecture impossible depuis une
   page publique), le spike s'arrête sur des options chiffrées : page régie servie par la LAN elle-même, application
   locale, ou proxy.

## Tests et gates

7. Endpoint d'essai : un test fonctionnel (désactivé = 404, bonne clé = 200, mauvaise clé ou action `read` = 401).
8. `composer gates` vert ; `pnpm gates` vert si la page d'essai est dans le frontend.
9. Aucune clé ni secret réel commité (`ops/lan-media/` contient des exemples `*.example`).

## Tasks / Subtasks

- [x] **Task 1** (AC 2) - MediaMTX épinglé en compose sur une machine de test, `mediamtx.yml`, certificat DNS-01 sur
  un nom de test (procédure écrite, certificat réel à obtenir sur la LAN).
- [x] **Task 2** (AC 1, 3, 7) - Endpoint d'essai `media-auth`, essais du jeton WHIP et SRT (questions 1 et 4).
- [ ] **Task 3** (AC 1, 4, 5) - Lecture WHEP depuis une page HTTPS dans OBS et dans Chrome (questions 2 et 3). Page
  d'essai livrée et lecture vérifiée en local (http) ; essai HTTPS public vers IP privée dans OBS à faire sur la LAN.
- [ ] **Task 4** (AC 1, 5) - Charge, latence, coupures, API des chemins, Internet coupé (questions 5 à 7). Questions 6
  et 7 tranchées ; charge et latence (5) à mesurer sur la LAN.
- [x] **Task 5** (AC 6) - Recommandation, mise à jour de l'epic, Dev Agent Record.

## Dev Notes

- Les essais demandent du matériel : au moins deux PC sur le même réseau (un joueur, une régie), idéalement un
  troisième pour la charge. Jean fait ou valide les mesures de la question 5 sur une config proche de la LAN.
- Pas de `date()` ni de `time()` dans l'endpoint d'essai ; journaliser par `LoggerInterface`.
- Variable d'environnement d'essai à passer par `src/lib/env.ts` côté frontend si la page d'essai en a besoin.
- Ne pas exposer MediaMTX sur Internet pendant les essais : le certificat DNS-01 n'en a pas besoin.

## Dev Agent Record

### Environnement d'essai (2026-11-11)

MediaMTX v1.21.3 (image `bluenviron/mediamtx:1.21.3`, Docker Desktop sur Windows 11, ports publiés), API en
`php -S 0.0.0.0:8000`, frontend en `next start`, Chrome à jour piloté par DevTools, ffmpeg 8.0.1 (SRT). Pas d'OBS ni
de deuxième machine : les questions 2, 3 et 5 restent à mesurer sur la LAN. Les réponses 1, 4, 6 et 7 sont
confirmées par l'essai et par le code de MediaMTX v1.21.3 (`internal/auth/credentials.go`, `internal/auth/manager.go`).

### Réponses

1. **Jeton WHIP : tranché.**
   - MediaMTX lit `Authorization: Bearer <x>`. Sans « : », `x` part dans `token`. Avec un seul « : », `user:pass`
     partent dans `user` et `password` (et `token` est aussi rempli).
   - En SRT (`streamid=publish:<chemin>:<user>:<clé>`), la clé part dans `password` (et `token`).
   - Corps reçu par l'API : `{ip, user, password, token, action, path, protocol, id, query, userAgent}`, avec
     `protocol` à `webrtc` ou `srt`.
   - Essais :
     - WHIP, bonne clé : autorisé (puis 400 sur la fausse offre SDP) ;
     - WHIP, mauvaise clé : 401 renvoyé par MediaMTX ;
     - SRT, bonne clé : publication acceptée ;
     - SRT, mauvaise clé : ffmpeg échoue en « I/O error ».
   - Un chemin hors `p-*` est refusé par MediaMTX avant toute demande au site (« path 'regie' is not configured »).
   - **Pour 47.5** : la clé ne doit jamais contenir « : ». L'API la cherche dans `token`, puis dans `password`.
2. **Lecture depuis une page HTTPS publique : à mesurer sur la LAN.** Ce qu'on sait déjà :
   - La lecture WHEP est vérifiée depuis une page du site (`/o/whep-essai`), dont l'origine diffère de celle de
     MediaMTX. Le CORS se règle par `webrtcAllowOrigins`.
   - Depuis la version 142, Chrome applique le Local Network Access : une page publique qui appelle une IP privée
     déclenche une demande d'autorisation (un clic, mémorisé par site). La règle porte sur la requête HTTP de
     signalisation WHEP, pas sur les médias WebRTC.
   - La source navigateur d'OBS 31 embarque Chromium 127 (CEF 6533), antérieur à cette règle. A priori, pas de
     blocage. Mais si une future version d'OBS l'applique, il n'y aura aucune fenêtre pour accepter.
   - **Plan B si OBS bloque** : servir l'écran régie depuis la LAN. Un proxy local `https://regie.lan.archilan.fr`
     renvoie vers le site ; la page a alors une origine privée qui appelle une IP privée, hors de la règle. Le code
     de l'écran ne change pas.
3. **Certificat : procédure écrite** (`ops/lan-media/README.md`, lego en DNS-01). Elle reste à dérouler pour de vrai
   avec les accès du fournisseur DNS d'archilan.fr. Risque relevé : un DNS qui filtre les réponses en IP privée.
4. **WebRTC et flux SRT : tranché.**
   - H264 sans B-frames + Opus : lu (1280x720, 31 i/s, gigue de 7 à 10 ms, aucune perte).
   - H264 avec B-frames : lecture refusée (« WebRTC doesn't support H264 streams with B-frames »).
   - AAC : piste ignorée (« skipping track 2 (MPEG-4 Audio) »), l'image passe.
   - WHIP depuis OBS envoie déjà sans B-frames et en Opus.
   - **Pour 47.7** : en SRT, le texte du joueur doit imposer B-frames à 0.
5. **Charge : à mesurer sur la LAN.** Indication locale : MediaMTX consomme 7 % d'un cœur et 43 Mo avec deux flux
   720p30 à 2,5 Mbit/s et un lecteur. Côté navigateur, il faut un lecteur WHEP par case.
6. **API des chemins : tranché.**
   - `GET /v3/paths/list` renvoie `{itemCount, pageCount, items: [{name, confName, online, onlineTime, available,
     source: {type, id}, tracks, inboundBytes, readers}]}`.
   - `ready` et `readyTime` sont dépréciés en 1.21 : **utiliser `online` et `onlineTime`** (47.6).
   - Un flux coupé disparaît aussitôt de la liste (`itemCount: 0`).
   - L'agent relie le chemin à l'inscription par son nom (`p-<identifiant>`) ; il n'a rien d'autre à lire.
7. **Internet coupé : tranché.**
   - Si l'API est injoignable, une nouvelle publication est refusée en 4 s environ (« HTTP request failed ...
     connection refused »).
   - Un flux déjà ouvert continue : vérifié, il reste `online` et ses octets montent.
   - La lecture est exclue de l'autorisation (`authHTTPExclude: read`) : la régie lit sans Internet.

### Points découverts

- **Coupure côté lecteur (corrigée dans le spike)** : quand un joueur coupait son flux, WebRTC ne passait en échec
  qu'au bout de 25 s environ (délai ICE), avec la dernière image figée à l'écran. Pendant les ~10 s où MediaMTX croit
  encore le flux SRT ouvert, un nouvel essai se connectait aussi et affichait « En direct » sans image. Correctif
  dans `whep-player.tsx` :
  - un lecteur connecté sans nouvelle image décodée pendant 3 s (`watchStall`, `STALL_TICKS`) est coupé et relancé ;
  - « En direct » attend la première image, et non plus la seule connexion ;
  - l'image et les statistiques sont effacées dès la coupure.
  Revérifié en local : coupure affichée environ 3 s après l'arrêt des images, reprise seule quand le flux revient.
  **Pour 47.8** : garder ce comportement, et retirer la case dès que l'agent ne voit plus le flux.
- MediaMTX 1.21 active MoQ par défaut (ports 8892 et 8893) : je l'ai coupé dans `mediamtx.yml`.
- Le message d'erreur de l'API (401) apparaît dans les journaux de MediaMTX : il ne doit rien dire de sensible.
  C'est le cas (« Refusé. »).

### Recommandation

- **WHIP en mode principal, SRT en repli**, comme prévu. Le jeton est la clé brute, sans « : ».
- **Lecture libre sur la LAN**, autorisation seulement pour la publication.
- **Agent qui pousse l'état complet**, lu sur `online` (47.6), comme prévu.
- Aucune décision de 47.5 à 47.9 ne change. Les précisions sont ajoutées à l'epic : clé sans « : », `online`,
  détection des lecteurs figés, B-frames en SRT, plan B de l'écran servi par la LAN.
- **Reste à faire sur la LAN avant 47.8** (Jean, deux PC) :
  - obtenir le certificat réel ;
  - publier depuis OBS joueur en WHIP ;
  - lire dans la source navigateur OBS de la régie sur `https://archilan.fr/o/whep-essai` (préprod ou prod, avec
    `MEDIA_AUTH_SPIKE_KEY`) ;
  - mesurer avec 6 puis 9 joueurs, et la latence au chrono.
- 47.5 et 47.6 peuvent commencer sans attendre ces essais.

### Livré

- `ops/lan-media/` :
  - `mediamtx.yml` commenté ;
  - `compose.yml` (image épinglée, réseau de l'hôte) ;
  - `.env.example` ;
  - `.gitignore` (`.env`, `certs/`) ;
  - `README.md` (installation, ports, certificat, OBS, vérification, essai local).
- API : `POST /api/v1/media-auth` (`MediaAuthController`, `MediaPublishSpikeGate`).
  - Désactivé sans `MEDIA_AUTH_SPIKE_KEY` (404).
  - Accepte la publication seulement, sur un chemin `p-*`, avec la clé dans `token` ou `password`.
  - Journalise chaque demande sans secret (`streaming.media_auth_spike`).
- Frontend :
  - `features/streaming/whep-player.tsx` (`negotiateWhep`, `WhepPlayer`, `WhepTestScreen`), qui sert de base à 47.8 ;
  - page `/o/whep-essai?url=...` (jusqu'à 9 lecteurs, avec leurs statistiques).
- Tests :
  - `MediaPublishSpikeGateTest` (unitaire) ;
  - `MediaAuthSpikeTest` (fonctionnel, clé de `.env.test`) ;
  - `whep-player.test.tsx`.

### File List

- ops/lan-media/README.md, mediamtx.yml, compose.yml, .env.example, .gitignore (nouveaux)
- api/src/Streaming/Application/Service/MediaPublishSpikeGate.php (nouveau)
- api/src/Streaming/Presentation/Controller/MediaAuthController.php (nouveau)
- api/config/services.yaml, api/.env, api/.env.test
- api/tests/Unit/Streaming/MediaPublishSpikeGateTest.php, api/tests/Functional/MediaAuthSpikeTest.php (nouveaux)
- frontend/src/features/streaming/whep-player.tsx, whep-player.test.tsx (nouveaux)
- frontend/src/app/(overlay)/o/whep-essai/page.tsx (nouveau)
- _bmad-output/planning-artifacts/epics/epic-47-multistream.md (précisions du spike)
