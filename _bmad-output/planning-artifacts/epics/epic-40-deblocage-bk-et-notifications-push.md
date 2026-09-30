# Epic 40: Déblocage BK notifié et notifications push

**Statut :** proposé le 2026-09-29
**Date :** 2026-09-29
**Origine :** demande de Jean le 2026-09-29 - être prévenu, dans le site et par une notification système
qu'on peut bloquer, quand on passe de BK à débloqué dans une partie privée.

## Objectif

Qu'un joueur bloqué (BK : plus aucun check atteignable) dans une partie privée n'ait pas à surveiller la page :

1. le site détecte qu'un slot **sort d'un vrai BK** (au moins 2 minutes) et prévient le joueur du slot et ses
   co-joueurs par une **notification du site** (cloche, temps réel) ;
2. chacun peut activer, **par appareil**, les **notifications push du navigateur** : la notification arrive en
   popup système **même site fermé**, et se bloque ou se coupe depuis le compte ou le navigateur.

## Existant

- **BK** : jamais calculé ni stocké côté serveur. Le front le déduit du payload `players`
  (`PlayerProgressGrid`, `isBK = !isGoal && reachable_now === 0 && checks_done < checks_total`).
  `reachable_now` vient du bridge (`reachable.py` en démon), `null` tant qu'il n'a pas été calculé.
- **Push `players`** : `POST /internal/sessions/{sessionId}/players-push` (`PlayersPushController`) à chaque
  changement d'état **et après chaque recalcul d'atteignabilité** (story 9.23, livrée dans le bridge).
  `RecordPlayersSnapshot` charge l'ancien snapshot avant de l'écraser.
- **Notifications du site** : `Notifier::notify()` (table `community_notification`, type en `varchar(32)`),
  publication Mercure sur le topic privé de l'utilisateur, rendu dans `notification-center.tsx`
  (`messageFor`, `hrefFor`). Aucune préférence par type.
- **Parties privées** : `Run` (`ownerId`, `sessionId`, seed importée sans suivi détaillé) ; pour une run privée,
  `SessionSlot::registrationId` est directement l'id de l'utilisateur ; co-joueurs via `SlotCoPlayer` /
  `SlotsPlayedBy`.
- **Notifications navigateur** : rien (ni service worker, ni Web Push).

## Principes

- **Aucun changement du bridge ni de l'orchestrateur** : tout part du `players-push` existant.
- **Parties privées seulement** (hors seed importée, sans suivi) ; événements et hebdos hors périmètre.
- **Anti-spam** : un BK ne compte qu'après 2 minutes continues ; une seule notification par épisode ;
  `reachable_now = null` (pas encore calculé) n'est ni un BK ni un déblocage.
- **Effets après commit, asynchrones** (Messenger) ; le push ne bloque jamais l'ingestion du bridge.
- **Le push est un canal de plus, pas un autre système** : une notification du site d'un type « poussable »
  part aussi en Web Push vers les appareils abonnés du destinataire.

## Stories

- **40.1** - Déblocage BK détecté et notifié dans le site (API + cloche).
- **40.2** - Notifications push du navigateur (Web Push, service worker, abonnement par appareil).
