# Story 47.7 - « Diffuser pour la régie » : la carte du joueur

**Status:** review (vérification réelle à faire)
**Epic:** 47 - Multistream des participants, partie 2 « Diffusion locale par MediaMTX »
(`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-47-story-7-carte-diffusion` (depuis la branche de la 47.6, PR empilée sur #1093)
**Dépend de:** 47.5 (clé et adresses, `GET/POST /registrations/{id}/media-publish`), 47.6 (état des flux), 53.3
(outils de l'espace inscrit), 53.5 (guides), 53.8 (mail de la veille).

## Story

En tant que joueur inscrit à une LAN,
je veux trouver dans mon espace de quoi envoyer mon écran à la régie, et savoir si elle me reçoit,
afin de régler OBS une fois, sans demander à l'orga.

## Décisions

1. **Lecture** : `GET /registrations/{id}/media-publish` gagne `live` (booléen : l'agent voit le flux du joueur en
   direct, 47.6). Faux sans accès.
2. **Carte « Diffuser pour la régie »** (`features/streaming/media-publish-card.tsx`) dans les outils de l'espace
   (`hub-tools.tsx`), juste après la carte du consentement, seulement si `available` (serveur média, inscription
   confirmée, diffusion non refusée, de la veille à la fin). Relue toutes les 15 s.
   - État : « La régie te reçoit » (`live`) ou « En attente de ton flux ».
   - **WHIP** (recommandé, OBS 30 ou plus) : `Paramètres > Stream`, service WHIP, serveur et jeton Bearer, chacun
     avec un bouton Copier. La clé est masquée par défaut (bouton Afficher).
   - **SRT** (repli, replié par défaut) : l'URL complète, copiable, avec le rappel « B-frames à 0, sinon la régie ne
     peut pas lire ton flux ».
   - Réglages conseillés : 720p60 ou 1080p60, encodeur GPU, 6000 kbit/s, image clé toutes les 1 à 2 s.
   - « Régénérer ma clé » en deux temps (le second bouton confirme : l'ancienne clé cesse aussitôt de marcher).
   - Lien d'aide vers le guide `diffusion`.
3. **Guide `diffusion`** (53.5) : section « Envoyer ton écran à la régie (LAN) ». Le guide de départ est modifié
   dans sa migration `Version20261111100001` (non releasée) ; une base qui l'a déjà jouée garde l'ancien texte, que
   l'admin peut compléter dans l'app.
4. **Mail de la veille** (53.8) : si l'événement a un serveur média et que l'inscrit n'a pas refusé la diffusion,
   une ligne « Tu peux envoyer ton écran à la régie : tes réglages de diffusion sont dans ton espace. » Jamais la
   clé.

## Critères d'acceptation

1. La carte n'apparaît que pour un inscrit qui a accès ; elle donne serveur, jeton (masqué) et URL SRT copiables.
2. L'état passe à « La régie te reçoit » quand l'agent voit le flux, et revient à « En attente » sinon.
3. « Régénérer ma clé » demande confirmation, puis affiche la nouvelle clé.
4. Le guide `diffusion` explique la diffusion locale ; le mail de la veille la mentionne sans clé.

## Tests et gates

5. API : `live` dans `media-publish` (vrai après un envoi de l'agent, faux sinon) ; mail de la veille avec et sans
   la ligne.
6. Front : rendu de la carte (accès, masquage de la clé, état, SRT), absence sans accès, garde de type.
7. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 2) - `live` dans `MediaPublishAccess`.
- [x] **Task 2** (AC 1-3) - API front, carte, intégration dans les outils.
- [x] **Task 3** (AC 4) - Guide et mail de la veille.
- [x] **Task 4** (AC 5-7) - Tests, gates.

## Dev Agent Record

### Completion Notes

- API : `media-publish` renvoie `live`, lu par `LocalStreamsState::isLive` (47.6). Mail de la veille : drapeau
  `localBroadcast` (serveur média et diffusion acceptée), ligne `EventEveReminderEmail::LOCAL_BROADCAST_LINE` en
  texte et en HTML, jamais la clé.
- Front :
  - `media-publish-api.ts` : type, garde, lecture et régénération ;
  - `media-publish-card.tsx` : `MediaPublishCard` (relue toutes les 15 s, rien sans accès) et `MediaPublishPanel`.
    La clé reste masquée tant que le joueur ne l'affiche pas, y compris dans l'adresse SRT ; la régénération se
    fait en deux temps ;
  - la carte est montée dans `hub-tools.tsx` après le consentement, pour un inscrit confirmé.
- Guide `diffusion` complété dans `Version20261111100001` (53.5, non releasée). Une base de dev qui l'a déjà jouée
  garde l'ancien texte : à compléter dans l'admin si besoin.
- Pas vérifié dans un vrai navigateur : la carte demande un inscrit confirmé, un événement avec serveur média dans
  sa fenêtre et un agent. Le rendu est couvert par les tests ; à voir sur la LAN d'essai.
- Gates : suite API complète en ParaTest (3884 tests), PHPStan, cs-fixer, DDD, rector ; front typecheck, lint,
  1653 tests, build.

### File List

- api/src/Streaming/Application/Service/MediaPublishAccess.php, LocalStreamsState.php
- api/src/Communications/Application/Email/EventEveReminderEmail.php
- api/src/Communications/Application/Message/EventEveReminderMessage.php
- api/src/Communications/Application/Handler/EventEveReminderHandler.php
- api/src/Registrations/Application/Handler/SendEventEveRemindersHandler.php
- api/migrations/Version20261111100001.php (guide `diffusion`)
- api/tests/Functional/MediaPublishTest.php, SendEventEveRemindersTest.php, LocalStreamsReportTest.php (commentaire)
- frontend/src/features/streaming/media-publish-api.ts, media-publish-card.tsx, media-publish.test.tsx (nouveaux)
- frontend/src/features/registrant-hub/hub-tools.tsx
