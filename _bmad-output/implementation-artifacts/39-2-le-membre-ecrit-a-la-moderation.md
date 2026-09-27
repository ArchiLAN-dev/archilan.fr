# Story 39.2: Le membre écrit à la modération depuis le site

**Status:** review
**Epic:** 39 - Modération synchronisée avec Discord
**Date:** 2026-09-27

## Story

En tant que membre sanctionné,
je veux écrire à la modération depuis le site, même banni ou suspendu et sans compte Discord,
afin de contester ma sanction ou de demander quelque chose (« OK pour le ban, mais j'aimerais être remboursé »).

En tant que membre du staff,
je veux retrouver ces messages dans le dossier du membre (page admin) et dans son post du forum staff,
afin d'en discuter entre nous au même endroit.

## Contexte

La 39.1 a posé le dossier (`ModerationCase`) et son post dans le forum staff. Un membre **banni ou suspendu ne
peut plus se connecter** (403 `account_banned` / `account_suspended` au login, story 30.29) et la connexion
Discord le renvoie sur `/compte` sans session valide : « depuis l'espace compte » ne vaut donc que pour un
membre averti. Pour les autres, il faut un chemin qui ne rouvre pas l'accès au site.

**Choix retenu : un laissez-passer de contact.** Quand le mot de passe (ou Discord) est validé mais le compte
bloqué, l'API pose un cookie HttpOnly signé, valable une heure, limité au seul chemin
`/api/v1/moderation-contact` : il ne sert qu'à lire l'état de sa sanction et à écrire à la modération, jamais à
autre chose. Aucune donnée dans l'URL, et rien n'est émis tant que les identifiants ne sont pas prouvés.

## Critères d'acceptation

1. **Membre averti, connecté** : dans son espace compte, une section « Modération » (visible seulement s'il a
   déjà reçu une sanction) lui permet d'écrire à la modération et lui montre ses messages envoyés.
2. **Membre banni ou suspendu** : au login refusé (mot de passe correct), la page de connexion affiche la
   sanction (motif, fin de suspension) et un formulaire « Contacter la modération ». Même chose après une
   connexion Discord refusée (`/connexion?discord_error=account_blocked`), qui ne pose plus de session.
3. **Laissez-passer** : cookie HttpOnly, Secure, SameSite Lax, chemin `/api/v1/moderation-contact`, signé avec
   un usage dédié (un cookie de session ne vaut pas laissez-passer, et inversement), valable 1 h. Sans lui, ou
   expiré : 401.
4. **Enregistrement** : le message (1 à 2000 caractères) est rattaché au dossier du membre, ouvert au besoin s'il
   a au moins une sanction ; un membre jamais sanctionné est refusé. Au plus 5 messages par heure.
5. **Forum staff** : après commit, en asynchrone, le bot poste le message dans le post du dossier (le crée au
   besoin), sans changer son étiquette, avec la même gestion d'échec que la 39.1.
6. **Page admin** : le panneau de modération liste les messages du dossier (auteur, date, texte).
7. Aucune mention ne notifie qui que ce soit ; le texte du membre n'est jamais interprété comme une mention.
8. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 4) - `ModerationCaseMessage`, dépôt, migration ; service d'écriture et ses refus.
- [x] **Task 2** (AC 5, 7) - Envoi au forum : job, handler, message ; livraison au post mise en commun avec la
  39.1.
- [x] **Task 3** (AC 2, 3) - Laissez-passer : signature, émission au login et au retour Discord, routes
  `/api/v1/moderation-contact`.
- [x] **Task 4** (AC 1) - Routes du membre connecté `/api/v1/account/moderation-contact`.
- [x] **Task 5** (AC 6) - Messages dans le panneau admin.
- [x] **Task 6** (AC 1, 2, 6) - Front : connexion, espace compte, panneau admin.
- [x] **Task 7** (AC 8) - Gates.

## Dev Agent Record

- **Domaine** : `ModerationCaseMessage` (Community) - auteur (membre), source (site), texte de 1 à 2000
  caractères ; migration `Version20260927180000` (index `case_id, created_at`). `ModerationCase::findById` ajouté
  au dépôt.
- **Commande** `ContactModeration::write` -> `ContactModerationOutcome` (`Sent`, `Invalid`, `NotSanctioned`,
  `TooMany`) : ouvre le dossier au besoin si le membre a au moins une sanction (les sanctions antérieures à la
  39.1, ou prises sans forum configuré, n'en ont pas) ; 5 messages par heure glissante ; envoi de
  `PostModerationMessageToForumJob` après le flush, un bus indisponible est journalisé sans perdre le message.
- **Forum** : livraison mise en commun dans `ModerationForumDelivery` (ouverture ou alimentation du post, échec
  passager relancé, refus définitif journalisé), utilisée par le handler de la 39.1 et par
  `PostModerationMessageToForumHandler`. Message « Message du membre » sans étiquette (le post garde celle de la
  dernière sanction).
- **Laissez-passer** `ModerationContactPass` (Identity) : HMAC avec une clé dérivée de l'app secret pour cet
  usage seul, 1 h, cookie `archilan_moderation_contact` (HttpOnly, Secure, Lax, chemin
  `/api/v1/moderation-contact`). Émis par le login refusé (mot de passe correct) et par le retour Discord, qui
  renvoie maintenant `DiscordAuthOutcome::AccessBlocked` pour un compte bloqué : plus de session posée, redirection
  vers `/connexion?discord_error=account_blocked`. Avant, le retour Discord posait des cookies de session pour un
  compte bloqué, rejetés ensuite à chaque requête.
- **Routes** (`ModerationContactController`) : `GET|POST /api/v1/moderation-contact` (laissez-passer, compte
  encore bloqué, sinon 401) et `GET|POST /api/v1/account/moderation-contact` (session). Refus : 422, 403, 429.
- **Admin** : `case.messages` (auteur, nom, texte, source, date) dans le panneau de modération.
- **Front** : `features/moderation-contact` (API, `ModerationContactThread`, conteneurs) ; formulaire sous le
  refus de connexion (mot de passe ou Discord) ; section dans l'aperçu du compte, visible seulement pour un membre
  déjà sanctionné ; « Messages du dossier » dans le panneau admin.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `ModerationCaseMessageTest`, `ContactModerationTest`, `PostModerationMessageToForumHandlerTest`, `ModerationContactPassTest`, `ModerationContactTest` (fonctionnel) | 23 erreurs, 2 échecs | verts |
| `DiscordAuthBlockedAccountTest` (commande et contrôleur) | classe et cas absents | verts |
| `AdminAccountModerationOverviewTest` (clé `messages`) | 1 échec | vert |
| `moderation-contact-api.test.ts`, `moderation-contact-thread.test.tsx`, `admin-user-moderation-case.test.ts` | modules absents, 1 échec | verts |

Gates : `composer gates` (2351 tests) et `pnpm gates` (572 tests, build) verts. Pas de vérification visuelle
dans le navigateur : elle demanderait la migration sur la base de dev partagée.
