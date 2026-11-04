# Story 50.3 - Durcissement de l'API

**Epic :** 50 - Sécurité
**Statut :** review
**Date :** 2026-11-04
**Branche :** `feature/epic-50-story-3-durcissement-api`

## Story

En tant que responsable de la plateforme, je veux que l'API applique des limites et des contrôles d'accès homogènes,
afin que les comptes et les données des joueurs restent protégés.

## Critères d'acceptation

1. **Limites de débit.** Connexion, mot de passe oublié, réinitialisation, inscription et renvoi de confirmation sont
   limités par adresse, et la connexion aussi par adresse et compte. Au-delà : 429, `too_many_requests`, `Retry-After`.
   Les limites par adresse tiennent compte d'un événement LAN, où tout le monde partage une adresse publique.
2. **Adresse du client.** L'API lit l'adresse du visiteur derrière le reverse proxy (`TRUSTED_PROXIES`, réseaux privés
   par défaut).
3. **OAuth Discord.** Connexion et liaison ne se terminent que dans le navigateur qui les a commencées, une seule fois.
4. **Suivi détaillé.** `GET /sessions/{id}/slots/{n}/reachable` est réservé aux participants et aux admins. L'overlay
   OBS, public par conception, lit `GET /public/overlay/{id}/slots/{n}/reachable` : le dernier résultat poussé, vue
   joueur sans sphères, sans aucun appel au bridge.
5. **Résultats d'event.** Ils suivent la règle de visibilité de la lecture de l'event.
6. **Secrets internes.** Toute comparaison de secret passe par `hash_equals`.
7. **Rôle Discord adhérent.** Il suit l'adhésion réelle, jamais `ROLE_MEMBER`.
8. **Images de tutoriel.** Compte à l'email confirmé, quota par compte (les admins en sont dispensés), plafond en pixels.
   Les GIF de profil ont aussi un plafond en pixels.
9. **Comptes.** La connexion met le même temps à refuser une adresse inconnue et un mauvais mot de passe. Les adresses
   email sont masquées dans les logs.
10. **En-têtes.** L'API envoie `X-Content-Type-Options` et HSTS, et plus `X-Powered-By`. Le frontend non plus ; il
    envoie une CSP en `Report-Only`.
11. **Tests d'accès.** Spoiler, patchs, état des slots des hebdos, réponse à un duel, résultats, liaison Discord.

## Tâches

- [x] `AuthRateLimitListener`, limiteurs dans `rate_limiter.yaml`, `trusted_proxies` (AC 1, 2)
- [x] Nonce du `state` Discord et cookie `DiscordStateCookie` (AC 3)
- [x] Contrôle d'accès du suivi, port `PublicReachabilitySnapshotsInterface`, route publique de l'overlay (AC 4)
- [x] `SessionResultsQuery` (AC 5)
- [x] `hash_equals` dans les contrôleurs internes (AC 6)
- [x] `SyncDiscordRoleMessageHandler`, AC-M3 de `api/CLAUDE.md` (AC 7)
- [x] Quota et limites de `TutorialImageController`, `CustomImageRule` (AC 8)
- [x] `AuthenticateUser`, `EmailMask` (AC 9)
- [x] `SecurityHeadersListener`, `expose_php`, `next.config.ts` (AC 10)
- [x] `PlayerDataAccessControlTest`, `DiscordOAuthStateTest`, `AuthRateLimitTest`, `WeeklyDuelTest` (AC 11)

## Notes de déploiement

- Frontend et API partent ensemble : l'overlay lit sa nouvelle route.
- Après la mise en prod, `app:discord:resync-roles` aligne les rôles Discord sur les adhésions.
- La CSP passe en mode bloquant en renommant l'en-tête, une fois la console propre.
