# Story 39.7: Ban posé sur Discord, répercuté sur le site

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre du staff d'ArchiLAN,
je veux qu'un ban posé directement sur le serveur Discord bannisse aussi le compte lié sur le site, et qu'un
débannissement sur Discord lève ce ban,
afin qu'une sanction soit la même partout, d'où qu'elle vienne.

## Contexte

Le site reste la source des sanctions (principe de l'épic) : un ban posé sur Discord passe par le site, qui
l'applique puis le rediffuse (dossier, MP, forum). Le bot n'écoute pas Discord : la liste des bans est relevée
toutes les 5 minutes. Les bans posés par le site portent le préfixe `[archilan.fr]` (39.5) et sont ignorés.

## Critères d'acceptation

1. **Ban venu de Discord** : un ban du serveur sans le préfixe `[archilan.fr]`, sur un compte lié et non banni
   sur le site, bannit le compte du site. Acteur « Discord » ; motif : auteur (journal d'audit, si le bot peut le
   lire) et raison du ban. La sanction suit le circuit habituel (dossier ouvert ou rouvert, MP, forum), sans
   rebannir sur Discord.
2. **Débannissement sur Discord** : un compte banni sur le site par un ban venu de Discord, qui n'est plus dans
   la liste des bans, est levé sur le site (acteur « Discord »). Un ban posé depuis le site n'est jamais levé
   ainsi.
3. **Sûreté** : les levées ne sont décidées que sur une liste des bans lue en entier ; si la lecture échoue, rien
   n'est levé.
4. **Compte non lié, ou admin du site** : rien sur le site (un admin n'est jamais sanctionné) ; un post dans le
   forum staff, une seule fois par ban. Si le ban disparaît puis revient, il est signalé de nouveau.
5. **Idempotence** : un compte déjà banni sur le site n'est pas rebanni ; relancer le relevé ne change rien.
6. La fiche admin nomme l'acteur « Discord ».
7. `composer gates` et `pnpm gates` passent.

## Mise en place côté Discord

- **Bannir des membres** (lire la liste des bans), déjà demandé par la 39.5.
- **Voir les logs du serveur** : pour l'auteur du ban. Sans elle, le motif ne donne que la raison.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Port : liste complète des bans (paginée), auteurs depuis le journal d'audit.
- [x] **Task 2** (AC 1, 2, 6) - Ban et levée « Discord » dans le service de modération ; pas de rebannissement.
- [x] **Task 3** (AC 4) - Signalements mémorisés (entité, migration) et post du forum.
- [x] **Task 4** (AC 1 à 5) - Relevé planifié toutes les 5 minutes et son handler.
- [x] **Task 5** (AC 7) - Gates.

## Dev Agent Record

- **Port** : `DiscordServerSanctionsInterface::bans()` (liste complète, `GET /guilds/{serveur}/bans` page par
  page avec `after`, une page en échec fait échouer toute la liste) et `banAuthors()` (journal d'audit,
  `action_type=22`, entrée la plus récente par membre ; vide sans la permission). `DiscordBan::postedBySite()`
  reconnaît le préfixe `[archilan.fr]`.
- **Identity** : `MemberModerationGatewayInterface::userIdForDiscordId` et `currentlyBanned` (comptes bannis et
  liés, `UserRepositoryInterface::findBannedWithDiscord`).
- **Service** : pas de nouvelle méthode ; le relevé appelle `ban()` et `lift()` avec l'acteur
  `ModerationAction::ACTOR_DISCORD`, donc avec les garde-fous existants (un admin n'est jamais sanctionné) et le
  circuit habituel (dossier, MP, forum).
- **Pas de rebannissement** : le job de la sanction enregistre « banni » pour un ban venu de Discord sans rappeler
  Discord, sinon le motif serait réécrit avec le préfixe du site et le ban passerait pour un ban du site.
- **Relevé** `SyncDiscordBansHandler`, toutes les 5 minutes : bans sans préfixe sur un compte lié non banni ->
  ban du site (« Ban posé sur Discord par {auteur} : {raison} ») ; comptes bannis sur le site absents de la
  liste et dont la dernière action est un ban de l'acteur Discord -> levée (« Débanni sur Discord ») ; liste
  illisible -> journalisé, rien n'est levé.
- **Signalements** `DiscordBanNotice` (migration `Version20260928150000`) : compte non lié ou admin -> un post
  « Discord : {pseudo} » dans le forum, une fois ; supprimé quand le ban disparaît (un ban qui revient est
  signalé de nouveau) ; forum passagèrement indisponible -> rien n'est mémorisé, nouvel essai au passage suivant.
- **Fiche admin** : l'acteur `discord` s'affiche « Discord » (historique et forum).

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `DiscordServerSanctionsTest` (pages, échec de page, journal d'audit), `SyncDiscordBansHandlerTest`, `DiscordBanNoticeTest`, `PostModerationActionToForumHandlerTest` (pas de rebannissement), `MemberModerationGatewayDiscordTest`, `AdminAccountModerationOverviewTest` (acteur Discord), `ScheduleTest` | méthodes, classes et constante absentes | verts |

Gates : `composer gates` (2426 tests) et `pnpm gates` (575 tests, build) verts.
