# Story 49.4 - Écran Minimapperz pour la régie

**Epic :** 49 - Minimapperz (`planning-artifacts/epics/epic-49-minimapperz.md`)
**Statut :** review
**Date :** 2026-11-02
**Branche :** `feature/epic-49-story-4-ecran-minimapperz` (partie de la 49.3)

## Story

En tant qu'organisateur, je veux afficher le classement Minimapperz de la LAN sur le stream et sur l'écran de la
salle, afin que tout le monde suive la compétition en direct.

## Critères d'acceptation

1. **Tableau public.** `GET /api/v1/events/{id}/minimapperz/board` (sans authentification) renvoie `status`
   (`ok`, `unavailable`, `unreachable`), le titre de l'event, le classement (20 lignes max : rang, nom, score,
   parties, victoires) et les 5 dernières parties (heure, vainqueur, nombre de joueurs). Event inconnu : 404.
2. **Clé protégée.** Le site relaie Minimapperz : la clé ne quitte jamais l'API.
3. **Cache.** La réponse est gardée 20 secondes par event : quel que soit le nombre d'écrans, Minimapperz reçoit
   au plus deux appels par event et par période.
4. **Noms du site.** Un joueur relié par son code apparaît sous son pseudo du site ; les autres sous leur pseudo
   Minimapperz.
5. **Intégration coupée ou injoignable.** Coupée ou non configurée : `unavailable`, aucun appel. Minimapperz en
   erreur (clé refusée comprise) : `unreachable`, l'écran l'affiche sans planter.
6. **Écran.** `/o/minimapperz?event={id}` : overlay OBS transparent, top 5 en haut à droite par défaut (paramètres
   communs des overlays `pos`, `scale`, `bg`, plus `lines`), rien tant qu'il n'y a pas de classement.
   `&mode=ecran` : plein écran pour la salle, top 10 et dernières parties, avec un message selon l'état.
   Relecture toutes les 20 secondes.
7. **Admin.** Onglet Minimapperz de la page pelles de l'event : les deux liens à copier ou ouvrir, et un aperçu de
   l'overlay sur damier.

## Tâches

- [x] Port `ranking(slug, key)`, record `MinimapperzRankingEntry`, lecture stricte dans `MinimapperzHttpClient`
  (score entier ou décimal).
- [x] Query `MinimapperzBoard` (cache 20 s, noms du site via les codes reliés), `MinimapperzBoardController`.
- [x] Front : `fetchMinimapperzBoard` + garde de type, `minimapperz-screen.tsx` (overlay et écran),
  route `app/(overlay)/o/minimapperz`, panneau `minimapperz-screen-links.tsx`.
- [x] Tests : `MinimapperzBoardTest` (5), `minimapperz-screen.test.tsx` (5).

## Dev Notes

- Pas de migration, pas de variable d'env nouvelle.
- Le flux SSE `/live` de Minimapperz n'est pas utilisé : le relais par cache suffit et ne garde aucune connexion
  ouverte côté API.
