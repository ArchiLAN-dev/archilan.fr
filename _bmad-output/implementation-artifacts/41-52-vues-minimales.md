# Story 41.52: Vues minimales sur YouTube

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôts :** `ArchiLAN-dev/archilan-blind-test` (app) et `archilan.fr` (exemple d'env, doc)
**Origine :** Jean le 2026-10-25 : n'inclure que les rips qui ont atteint un certain nombre de vues, pour écarter
les morceaux moyens.

## Story

En tant qu'animateur,
je veux fixer un nombre minimal de vues YouTube pour qu'un rip entre dans la playlist,
afin de garder les mashups que la communauté a le plus aimés.

## Critères d'acceptation

1. **Clé YouTube Data API v3**, facultative (`YOUTUBE_API_KEY` dans `envs/blind-test.env`). Avec elle, les vidéos sont
   vérifiées par 50 en une requête, avec leur nombre de vues ; sans elle, ou si l'API refuse (quota, restriction),
   la vérification sans clé de la 41.44 continue (une vidéo à la fois, sans vues).
2. **Réglage « Vues minimales »** dans la fenêtre des réglages du tirage (0 = pas de seuil), gardé par brouillon ;
   grisé avec une explication sans clé.
3. **Génération** : une vidéo sous le seuil est écartée comme une vidéo morte, et le tirage continue jusqu'à couvrir
   le créneau ; la progression compte séparément les vidéos mortes et celles sous le seuil ; l'en-tête de la
   playlist note le seuil.
4. « Revérifier les vidéos » passe aussi par 50 avec la clé.

## Tâches

- [x] `VideoChecker` (Data API par 50, repli oEmbed), seuil dans l'import et les réglages.
- [x] Fenêtre des réglages, progression ; `YOUTUBE_API_KEY` documentée.
- [x] Tests (seuil, repli quand l'API refuse).

## Dev Agent Record

- App : branche `feature/41-52-vues-minimales`, posée sur `feature/41-51-musique-d-origine`.
- La clé Google existante du site (`GOOGLE_API_KEY`, synchro du catalogue par Google Sheets) a été essayée le
  2026-10-25 : refusée par YouTube (« Requests to this API youtube … are blocked »), la clé est restreinte à d'autres
  API. À faire par Jean : ajouter « YouTube Data API v3 » aux API autorisées de cette clé (et l'activer dans le
  projet), ou créer une clé dédiée.
- Quota : `videos.list` coûte 1 unité par requête de 50 vidéos ; 10 000 unités gratuites par jour.
