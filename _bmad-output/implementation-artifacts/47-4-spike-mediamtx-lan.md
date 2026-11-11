# Story 47.4 - Spike : MediaMTX sur la LAN, de la clé à l'écran régie

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 2) - MediaMTX épinglé en compose sur une machine de test, `mediamtx.yml`, certificat DNS-01 sur
  un nom de test.
- [ ] **Task 2** (AC 1, 3, 7) - Endpoint d'essai `media-auth`, essais du jeton WHIP et SRT (questions 1 et 4).
- [ ] **Task 3** (AC 1, 4, 5) - Lecture WHEP depuis une page HTTPS dans OBS et dans Chrome (questions 2 et 3).
- [ ] **Task 4** (AC 1, 5) - Charge, latence, coupures, API des chemins, Internet coupé (questions 5 à 7).
- [ ] **Task 5** (AC 6) - Recommandation, mise à jour de l'epic, Dev Agent Record.

## Dev Notes

- Les essais demandent du matériel : au moins deux PC sur le même réseau (un joueur, une régie), idéalement un
  troisième pour la charge. Jean fait ou valide les mesures de la question 5 sur une config proche de la LAN.
- Pas de `date()` ni de `time()` dans l'endpoint d'essai ; journaliser par `LoggerInterface`.
- Variable d'environnement d'essai à passer par `src/lib/env.ts` côté frontend si la page d'essai en a besoin.
- Ne pas exposer MediaMTX sur Internet pendant les essais : le certificat DNS-01 n'en a pas besoin.

## Dev Agent Record
