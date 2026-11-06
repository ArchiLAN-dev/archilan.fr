# Story 50.7: Brouillons et runs perso hors des listes publiques

**Status:** review
**Epic:** 50 - Sécurité
**Date:** 2026-11-06

## Story

En tant qu'organisateur ou propriétaire d'une run perso,
je veux que les brouillons et les runs perso restent hors des listes publiques,
afin que seule leur audience voie leurs participants.

## Contexte

Les récaps et la liste des parties suivent `SessionRecapAudience`. Les streams et le classement ne regardaient ni le
statut de l'event, ni l'audience d'une run perso.

**Décision de Jean (2026-11-06) :** sur un event privé publié, les streams des participants et le classement restent
publics, sans compte. Ces events ne sont pas confidentiels. Seuls leurs récaps et leur liste de parties sont réservés.

## Critères d'acceptation

1. **Brouillon** : un event en brouillon répond 404 à tous, sauf aux admins, sur `participant-streams` et sur le
   classement. Le board Minimapperz répond 404 à tous.
2. **Streams d'une run perso** : 404 sauf pour le propriétaire, les participants, les admins, ou si le récap est public.
3. **Classement** : une réponse filtrée par event n'est jamais mise en cache partagé, car un admin peut lire celle d'un
   brouillon. Un id inconnu garde sa réponse vide.
4. **Inchangé** : les streams et le classement d'un event publié, privé ou non, `broadcast-streams`, le board d'un event
   publié et les runs hebdo restent publics.
5. Gates verts, tests fonctionnels pour chaque audience.

## Tâches

- [x] Tâche 1 : règle dans `SessionRecapAudience` (AC 1, 2)
- [x] Tâche 2 : streams, classement, board (AC 1 à 3)
- [x] Tâche 3 : tests et gates (AC 4, 5)
