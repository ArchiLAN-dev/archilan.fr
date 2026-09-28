# Story 39.9: Durcissement avant mise en prod

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-28

## Story

En tant que responsable d'ArchiLAN,
je veux pouvoir déployer l'épic 39 sans qu'il agisse sur Discord avant que je l'active, sans rejouer l'historique
des bans du serveur, et sans que ses cas d'échec défassent une décision du staff,
afin de mettre la modération synchronisée en service sereinement.

## Contexte

Review globale de l'épic 39 après la 39.7 (trois relectures : sécurité, fiabilité des flux Discord, conception
et ergonomie). Deux points bloquent la mise en prod :

- `DISCORD_MODERATION_FORUM_ID` vide ne coupe que le forum : MP, ban, exclusion, relevé des MP et relevé des bans
  ne dépendent que de `DISCORD_BOT_TOKEN` et `DISCORD_GUILD_ID`, déjà renseignés en prod pour les rôles ;
- le premier relevé des bans appliquerait tout l'historique des bans Discord au site.

Décision (2026-09-28) : les bans déjà présents à l'activation sont **affichés une fois au staff pour décision**,
jamais appliqués au site.

## Critères d'acceptation

### Mise en service

1. **Interrupteur** `DISCORD_MODERATION_SYNC` (désactivé par défaut) : sans lui, le bot n'envoie ni ne lit aucun
   MP de modération et n'agit pas sur le serveur (ban, exclusion, levée, relevé des bans, prolongation). Le
   forum reste gouverné par `DISCORD_MODERATION_FORUM_ID`. Les issues l'indiquent (« synchronisation Discord
   désactivée »).
2. **État initial des bans** : au premier relevé après activation, chaque ban du serveur qui ne vient pas du site
   est signalé une fois dans le forum (« ban antérieur à la synchronisation, à décider : rien n'est appliqué sur
   le site »), avec le lien vers la fiche admin si le compte est lié, puis mémorisé. Aucun ban du site, aucune
   levée pendant ce passage. Si un signalement échoue passagèrement, l'état initial est repris au passage
   suivant. Ensuite, ces bans ne sont jamais appliqués ; un ban qui disparaît puis revient est traité comme un
   nouveau ban.

### Fiabilité

3. **Levée en attente** : un compte dont la dernière sanction (ban ou levée) est une levée pas encore appliquée
   sur Discord n'est jamais rebanni par le relevé ; la levée en échec est journalisée.
4. **Levée par Discord** : décidée sur la dernière sanction ban ou levée du compte, pas sur sa dernière action.
5. **Sanction dépassée** : un ban ou une suspension déjà levé sur le site au moment où son job passe n'est pas
   appliqué sur Discord (issue « dépassée ») et ne rouvre pas le dossier.
6. **Le MP ne bloque pas le serveur** : un échec passager du MP laisse le job appliquer d'abord la sanction sur
   Discord, puis retente le MP.
7. **Relevés hors du worker du scheduler** : relevé des MP, relevé des bans et prolongation des exclusions
   passent par `async`. Une erreur inattendue sur les auteurs des bans n'interrompt pas le relevé.
8. **Plafond des MP relevés** : au plus 5 réponses par heure et par dossier sont postées dans le forum ; les
   suivantes sont enregistrées dans le dossier (visibles sur la fiche) sans être postées. Le plafond du site ne
   compte que les messages écrits sur le site.
9. **Dossier clos** : les réponses aux MP sont encore relevées 30 jours après la clôture du dossier.

### Ergonomie

10. Le brouillon du membre n'est effacé qu'une fois le message accepté.
11. Page de connexion : la sanction n'est plus affichée deux fois ; le message d'erreur sert de repli tant que le
    fil n'est pas chargé ; un nouveau refus recharge le fil.
12. Les messages de l'API montrés au membre le tutoient.
13. Fiche admin : l'issue Discord d'une sanction ou d'une réponse se met à jour d'elle-même tant qu'elle est en
    cours.

### Documentation et nettoyage

14. `.env`, `envs/api.env.example` : interrupteur, permissions du bot (Bannir des membres, Exclure temporairement
    des membres, Voir les logs du serveur, forum), comportement du premier relevé, reprise des jobs en échec
    (`messenger:failed:retry`).
15. Code mort retiré (`SERVER_UNBANNED`, jamais sorti en prod), commentaires périmés corrigés ; test du laissez-
    passer pour un membre suspendu.
16. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Interrupteur dans les adaptateurs Discord de modération.
- [x] **Task 2** (AC 2, 3, 4) - Relevé des bans : état initial, levée en attente, levée par Discord.
- [x] **Task 3** (AC 5, 6) - Job de la sanction : sanction dépassée, MP non bloquant.
- [x] **Task 4** (AC 7, 8, 9) - Relevés en async, plafond des MP relevés, dossiers clos récemment.
- [x] **Task 5** (AC 10 à 13) - Front et messages.
- [x] **Task 6** (AC 14, 15) - Documentation, nettoyage, test manquant.
- [x] **Task 7** (AC 16) - Gates.

## Hors périmètre (story de nettoyage à venir)

Renommer et découper `PostModerationActionToForumHandler` ; factoriser « trouver ou ouvrir le dossier » ; libellés
partagés API / front ; fabrique du cookie hors du contrôleur ; tests des conteneurs front ; course à la création
d'un dossier ; motif Discord commençant par `[archilan.fr]` posé à la main.

## Dev Agent Record

- **Interrupteur** : `DiscordMemberDirectMessages` et `DiscordServerSanctions` ne sont configurés qu'avec
  `%env(bool:default::DISCORD_MODERATION_SYNC)%` ; `.env` pose `DISCORD_MODERATION_SYNC=0`. Issues « synchronisation
  Discord désactivée » (API et fiche admin).
- **État initial** : `DiscordBanBaseline` (une ligne, migration `Version20260928180000`) ; tant qu'il manque,
  `SyncDiscordBansHandler::takeBaseline` signale chaque ban hors site (`DiscordBanNotice::REASON_PREEXISTING`,
  lien vers la fiche si le compte est lié) et n'applique ni ban ni levée ; l'état est marqué seulement si tous
  ont été signalés. Ensuite tout ban déjà signalé (quelle que soit la raison) est ignoré.
- **Levée en attente** : `applyBan` ne rebannit pas si la dernière sanction ban ou levée est une levée dont
  l'issue serveur n'est pas `lifted` (`moderation_discord_bans.lift_pending`). `latestBanOrLift` sert aussi à la
  levée par Discord (un avertissement intermédiaire ne l'empêche plus). Auteurs des bans lus sous `try`.
- **Job de sanction** : `inEffect` (ban encore posé, suspension encore en cours) ; sinon MP `superseded`, serveur
  `superseded`, dossier non rouvert. Un échec passager du MP est retenu, l'étape serveur passe et est commitée,
  puis le MP est relancé.
- **Relevés** : les trois messages planifiés de l'épic routés sur `async`. Relevé des MP : dossiers ouverts ou
  clos depuis moins de 30 jours (`withDirectMessagesToRead`) ; au plus 5 réponses par heure et par dossier
  postées au forum, les autres gardées (`moderation_dm.forward_capped`). `countFromMemberSince` prend la source :
  le plafond du site ne compte que le site.
- **Front** : brouillon effacé seulement si le message est accepté (`onSend` rend une promesse) ; page de
  connexion : le bloc de contact remplace le message du refus une fois chargé (repli sinon), remonté à chaque
  refus sans cache (`gcTime: 0`) ; fiche admin rafraîchie toutes les 5 s tant qu'une issue Discord récente
  (moins de 10 minutes) est en cours (`hasPendingDiscordOutcome`).
- **Messages** : tutoiement (« Ton compte a été banni », « Reconnecte-toi », « réessaie »).
- **Nettoyage** : `SERVER_UNBANNED` retiré (jamais sorti en prod) ; commentaires de `.env`,
  `ModerationForumInterface`, `ModerationCase`, `messenger.yaml` corrigés ; `envs/api.env.example` documente les
  deux interrupteurs, les permissions du bot, le premier passage et `messenger:failed:retry`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| Interrupteur (`DiscordMemberDirectMessagesTest`, `DiscordServerSanctionsTest`) | signature | verts |
| `SyncDiscordBansHandlerTest` (état initial, reprise, levée en attente, nouveau ban après levée, levée après avertissement) | classes absentes | verts |
| `PostModerationActionToForumHandlerTest` (ban levé, suspension finie, MP passager) | 3 échecs | verts |
| `PollModerationDirectMessagesHandlerTest` (dossier clos récent, plafond), `ContactModerationTest` (source) | signatures | verts |
| `ModerationContactTest` (laissez-passer d'un suspendu) | - | vert |
| `admin-user-moderation-case.test.ts` (`hasPendingDiscordOutcome`) | 1 échec | vert |

Gates : `composer gates` (2438 tests) et `pnpm gates` (576 tests, build) verts.
