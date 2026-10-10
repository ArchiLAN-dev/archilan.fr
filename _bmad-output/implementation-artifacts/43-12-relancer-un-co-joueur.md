# Story 43.12: Relancer un co-joueur inactif

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02

## Story

En tant que joueur d'une run perso en asynchrone,
je veux envoyer une relance à un co-joueur qui n'a pas joué depuis plusieurs jours,
afin que la partie avance sans devoir le harceler sur Discord.

## Contexte

Équivalent du « nudge » Duolingo. Dans un multiworld asynchrone, un joueur absent bloque souvent les autres
(items à envoyer). Une run sans activité passe `idle` après 1 h (epic 17) et ne repart que sur relance manuelle :
c'est justement le cas où la relance sert. La dernière activité d'un slot se lit dans le feed persisté
(`SessionFeedEvent.occurred_at` où `sender_slot` = le slot, c'est-à-dire son dernier check).

## Critères d'acceptation

1. Sur la page d'une run perso `active` ou `idle`, à côté d'un participant dont aucun slot n'a fait de check
   depuis **au moins 48 h** (ou jamais, si la run a démarré il y a plus de 48 h), un bouton « Relancer » est
   visible des autres participants.
2. La relance envoie une notification `run_nudge` (cloche + push) : « *X* attend ta prochaine session dans
   *Titre* », lien vers la run.
3. Plafond : une relance par (run, destinataire) toutes les 24 h, tous expéditeurs confondus ; sinon l'expéditeur
   voit « Déjà relancé il y a *N* h ».
4. Le destinataire peut couper les relances pour une run donnée (« Ne plus me relancer pour cette partie »).
5. Pas de relance sur une run `draft`, `completed` ou `cancelled`, ni vers un participant dont tous les slots sont
   released ou ont atteint l'objectif, ni vers soi-même.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Application** : lecture de la dernière activité par participant (DBAL, une requête par run) ; commande
      `NudgeRunParticipant`.
- [x] **Domaine/Migration** : journal des relances (plafond) et opt-out par (run, utilisateur).
- [x] **Notifications** : type `run_nudge` poussable.
- [x] **Front** : bouton et états sur la page de run.
- [x] Tests (seuil 48 h, jamais joué, plafond partagé, opt-out, statuts exclus) et gates.

## Notes

- Pas besoin d'être amis : tout co-participant peut relancer.
- Idée écartée pour l'instant : chiffrer « tu as *N* items de progression pour les autres » (lecture du spoiler ou
  de la multidata, coûteuse et sensible aux spoilers).

## Dev Notes

- **Dernière activité** : lue dans `session_slot.last_check_at` (story 30.45, posé à chaque nouveau check) plutôt
  que dans le feed. C'est la même information, déjà agrégée par slot, sans parcourir `session_feed_event`.
  `DbalRunPlayerActivityQuery::forSession` fait une seule requête par run : par joueur (propriétaire du slot et
  co-joueurs via `DbalSlotPlayerSource`), le dernier check, « tous les slots finis » (released ou objectif) et le
  `started_at` de la session. Une reprise depuis `idle` garde la même session, donc le même point de départ.
- **Seuil** : `RunPlayersActivity::IDLE_AFTER` (48 h), compté depuis le premier démarrage pour qui n'a jamais fait de
  check.
- **Stockage** : une seule table `personal_run_nudge` (migration `Version20261010120000`), une ligne par
  (run, destinataire) : dernière relance et son expéditeur (plafond partagé de 24 h), et `muted` (opt-out). Les lignes
  partent avec la run (`PersonalRunDrafts::delete`).
- **Qui relance** : le propriétaire, un participant ou un joueur d'un slot de la session. Pas besoin d'être amis,
  mais pas de relance à travers un blocage (dans un sens ou dans l'autre).
- **Statuts** : `active` et `idle` seulement (`NudgeRunParticipant::PLAYING_STATUSES`).
- **Notification** : `run_nudge`, cloche + push par défaut, réglable dans « Ce qui me prévient » (ajouté à
  `NotificationPreferenceService::CONFIGURABLE`). Lien vers la run.
- **Routes** :
  - `GET /api/v1/runs/{runId}/nudges` : état par joueur (`idle`, `muted`, `nudgedHoursAgo`, `canNudge`) et l'opt-out
    de l'appelant ;
  - `POST /api/v1/runs/{runId}/nudges/{userId}` : 200, 403, 404, 409 (`run_not_playing`, `not_idle`, `muted`), 429
    `already_nudged` avec `data.hoursAgo` ;
  - `PUT /api/v1/runs/{runId}/nudges/mute` `{muted: bool}`.
- **Heures** : « Déjà relancé il y a N h » est calculé côté serveur (pas de `Date.now()` au rendu).
- **Front** : onglet Participants de la page de run, bouton à droite de chaque participant concerné, et lien « Ne
  plus me relancer pour cette partie » sous la liste pour qui joue dans la run.
- **Tests** : `RunNudgeTest` (fonctionnel), `RunPlayersActivityTest` (seuil exact de 48 h),
  `PushMessageFactoryTest`, `run-nudges.test.tsx`.
