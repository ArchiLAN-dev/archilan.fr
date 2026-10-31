# Story 47.2 - L'écran régie : tous les lives en mosaïque

**Status:** review
**Epic:** 47 - Multistream des participants (`_bmad-output/planning-artifacts/epics/epic-47-multistream.md`)
**Date:** 2026-10-31
**Branche:** `feature/epic-47-story-2-ecran-regie` (depuis `feature/epic-47-story-1-consentement-regie`, la 47.1
n'étant pas encore mergée ; la PR vise la branche de la 47.1, à recibler sur `develop` après son merge)

## Story

En tant que régie ArchiLAN,
je veux une source navigateur OBS qui montre les joueurs en live sur l'événement,
afin de diffuser tout le monde pendant les pauses sans monter une scène à la main.

## Contexte

- La 47.1 fournit `GET /api/v1/events/{eventId}/broadcast-streams` : inscrits confirmés en live, sauf ceux qui ont
  refusé, au format de participant-streams (`twitchLogin`, `displayName`, `viewerCount`...). Publique, cache Twitch.
- Modèle d'écran : `/o/partenaires` (story 45.4), groupe `app/(overlay)`, sans habillage, `QueryProvider` + `Suspense`,
  paramètres lus dans l'URL, fond transparent ou `?bg=` (clé chroma), liste relue à intervalle.
- Lecteur Twitch : `https://player.twitch.tv/?channel=<login>&parent=<hostname>` (comme `ParticipantStreamEmbed`).
  Le consentement cookies de la story 7.5 ne s'applique pas : la page n'est ouverte que par la régie dans OBS.

## Décisions

1. **Route** `/o/streams?event=<id>`. Sans `event` valide, rien à l'écran.
2. **Mode mosaïque** (défaut) : grille selon le nombre de lives, 1, 2 (côte à côte), 4 (2 x 2), 6 (3 x 2) ou 9 (3 x 3)
   cases. Plafond `?max=` de 1 à 9, **4 par défaut** (au-delà, OBS chauffe). Plus de lives que le plafond : les cases
   tournent par groupes toutes les `?duree=` secondes (30 par défaut, 5 à 600).
3. **Mode rotation** `?mode=rotation` : un live en grand, les autres en vignettes sur le côté (jusqu'à `max - 1`),
   le grand change toutes les `duree` secondes.
4. **Chaque case** : le lecteur Twitch muet en lecture auto (`muted=true&autoplay=true`, le son de la scène est géré
   par OBS), le pseudo du joueur en bandeau bas. Les iframes ont pour clé le login Twitch : une relecture de la liste
   ne recharge pas les lecteurs déjà à l'écran.
5. **Relecture** toutes les 60 secondes. Une lecture en échec garde la liste à l'écran.
6. **Personne en live** : rien du tout (fond transparent ou couleur `?bg=`), la régie garde sa scène de pause.
7. **Admin** : sur la page des inscriptions de l'événement, un encart « Écran régie » avec le lien à copier ou ouvrir et
   le rappel des réglages OBS. Pas d'aperçu intégré (il chargerait des lecteurs Twitch dans l'admin).

## Critères d'acceptation

1. `/o/streams?event=<id>` affiche en mosaïque les lives renvoyés par `broadcast-streams`, pseudo sur chaque case.
2. La grille suit le nombre de lives (1, 2, 4, 6, 9), plafonnée par `max` ; au-delà, les groupes tournent.
3. `?mode=rotation` montre un live en grand et les autres en vignettes, le grand tourne.
4. Personne en live ou pas d'événement : écran vide et transparent.
5. L'admin trouve le lien de l'écran régie sur la page des inscriptions de l'événement.
6. Tests : lecture des paramètres, grille et rotation (fonctions pures), lecture API, rendu de l'écran et de l'encart.
7. `pnpm gates` vert (pas de changement API) ; vérification réelle avec les données de test de la 47.1.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-4) - `fetchBroadcastStreams`, `broadcast-screen.tsx` (paramètres, grille, rotation, écran),
  route `app/(overlay)/o/streams/page.tsx`.
- [x] **Task 2** (AC 5) - Encart `BroadcastScreenLink` sur la page admin des inscriptions.
- [x] **Task 3** (AC 6-7) - Tests, gates, vérification réelle.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- `fetchBroadcastStreams` (null sur échec, l'écran garde sa liste), `broadcast-screen.tsx` : paramètres
  (`event`, `mode`, `max` 1-9 défaut 4, `duree` 5-600 défaut 30, `bg`), fonctions pures `mosaicColumns`,
  `mosaicGroup`, `rotationSlots`, écran `BroadcastScreen` (relecture 60 s, fond transparent ou clé chroma), cases
  `BroadcastCell` (lecteur Twitch muet en lecture auto, pseudo en bandeau). Route `app/(overlay)/o/streams`.
- Admin : encart « Écran régie » (`BroadcastScreenLinks`) sous la liste des inscriptions, liens mosaïque et rotation.
- Vérification réelle (Chrome) avec les données de test de la 47.1 sur la démo 4.23 : mosaïque 2 x 2 avec les 3 lives
  (Falche, qui a refusé, absent), rotation (un grand, deux vignettes, le grand change après `duree`), encart admin.
- À savoir pour OBS : une chaîne marquée « contenu pour public averti » demande un clic de confirmation dans le
  lecteur (clic droit sur la source, « Interagir »). Dans Chrome, la lecture auto peut attendre un clic ; la source
  navigateur d'OBS lance les lecteurs muets d'elle-même.
- `pnpm gates` vert (1342 tests). Pas de changement API.

### File List

- `frontend/src/features/streaming/participant-streams-api.ts` (modifié)
- `frontend/src/features/streaming/broadcast-screen.tsx` (nouveau)
- `frontend/src/features/streaming/broadcast-screen.test.tsx` (nouveau)
- `frontend/src/app/(overlay)/o/streams/page.tsx` (nouveau)
- `frontend/src/features/admin/broadcast-screen-links.tsx` (nouveau)
- `frontend/src/features/admin/admin-registration-dashboard.tsx` (modifié)
