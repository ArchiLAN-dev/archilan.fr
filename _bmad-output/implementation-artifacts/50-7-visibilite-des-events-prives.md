# Story 50.7: Les listes d'un event privé suivent la règle de l'event

**Status:** review
**Epic:** 50 - Sécurité
**Date:** 2026-11-06

## Story

En tant que membre inscrit à un event privé,
je veux que les listes de cet event restent réservées à son audience,
afin que ma participation suive la visibilité choisie par l'organisation.

## Contexte

Les récaps et la liste des parties suivent `SessionRecapAudience`. Trois lectures ne passaient pas par cette règle.

## Critères d'acceptation

1. **Streams d'un event** : `participant-streams` d'un event privé répond 404 hors audience (inscrits et admins).
2. **Brouillon** : un event en brouillon répond 404 à tous, sauf aux admins, sur `participant-streams` et sur le
   classement. Le board Minimapperz répond 404 à tous.
3. **Streams d'une run perso** : 404 sauf pour le propriétaire, les participants, les admins, ou si le récap est public.
4. **Classement** : `/leaderboard?eventId=` suit la même règle, et une réponse filtrée par event n'est jamais mise en
   cache partagé. Un id inconnu garde sa réponse vide.
5. **Front** : le filtre par event du classement envoie la session.
6. **Inchangé** : `broadcast-streams`, le board d'un event publié et les runs hebdo restent publics pour la régie.
7. Gates verts, tests fonctionnels pour chaque audience.

## Tâches

- [x] Tâche 1 : règle dans `SessionRecapAudience` (AC 1 à 4)
- [x] Tâche 2 : streams, classement, board (AC 1 à 4)
- [x] Tâche 3 : session sur le filtre du classement (AC 5)
- [x] Tâche 4 : tests et gates (AC 6, 7)
