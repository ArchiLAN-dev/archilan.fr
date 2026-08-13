# Epic 36: Espace de contrôle utilisateur (admin)

**Statut :** draft
**Date :** 2026-08-06
**Origine :** issue #250 (« Refacto: Page Admin User »), élargie par Jean le 2026-08-06 en un espace
de contrôle complet.

## Objectif

Donner à un admin une **fiche par utilisateur** qui rassemble tout ce qui le concerne, et depuis
laquelle il peut agir sur ses objets quand la personne n'est pas disponible.

> « Je veux un espace de contrôle des utilisateurs côté admin. Pour voir tout ce qu'il se passe et
> pouvoir agir à leur place si besoin. » - Jean, 2026-08-06

## Constat de départ (vérifié dans le code, 2026-08-06)

**Le domaine `users` est le plus pauvre de tout le backoffice** : 3 routes admin
(`GET /admin/users`, `PATCH /admin/users/{id}/role`, `POST /admin/users/admins`), contre 24 pour
`events`, 21 pour `sessions`, 17 pour `community`. L'utilisateur est pourtant l'entité à laquelle tout
le reste se rattache.

**Il n'existe aucune vue de détail par utilisateur.** `/admin/utilisateurs` est un tableau plat
(email, rôle, statut, date de création) avec un basculement `user` <-> `member` et un formulaire de
création d'admin. Aucune route `/admin/utilisateurs/{userId}`.

**L'information existe, mais elle est éclatée et jamais consultable « par personne » :** la modération
est dans `/admin/moderation`, les adhésions dans `/admin/adhesions`, les inscriptions **uniquement par
événement** (jamais « toutes celles de X »), les succès dans `/admin/achievements` (indexés par succès,
pas par membre), le lien Discord dans `/admin/discord`. Les **runs personnelles n'ont aucune surface
admin** - c'est le trou opérationnel que décrit l'issue #387.

**Cinq journaux d'audit sont écrits mais jamais lus.** `RoleChangeAudit`, `AdminCreationAudit`,
`DeletionAudit`, `RunAuditLog` et `EventPrivateAccessLog` sont alimentés côté API et référencés par
**zéro** fichier frontend. La traçabilité est déjà là ; elle est invisible. Cet épic est donc en grande
partie un travail d'**agrégation et d'exposition**, pas d'instrumentation.

**Beaucoup de lectures sont déjà réutilisables :** `AccountRegistrationsQuery::findForUser($userId)`
(aujourd'hui branchée sur `/compte/inscriptions`), `GET /admin/community/accounts/{userId}/actions`
(historique de modération), `CommunityLevelQuery`, les grants de succès, la liste d'adhésions admin.

## Décisions produit (Jean, 2026-08-06)

| Question | Décision |
|---|---|
| Sens de « agir à leur place » | **Actions ciblées sur ses objets**, jamais d'usurpation |
| Volets de la fiche | **Les quatre** : identité/rôles, modération, adhésion/inscriptions, jeu |

### Pourquoi pas d'impersonation

`switch_user` est présent mais **commenté** dans `security.yaml`, et l'authentification repose sur des
JWT courts + cookies de refresh à rotation avec détection de réutilisation (epic 13), pas sur des
sessions Symfony. L'impersonation native ne s'y branche pas : il faudrait une émission de jetons
dédiée, une bannière permanente, une expiration courte et un journal propre. Décision de Jean :
l'admin agit **depuis son propre compte**, chaque action étant attribuée à lui. Aucun jeton
d'usurpation n'est émis. Ce choix garde la surface d'attaque d'un compte admin compromis à ce que
l'admin peut déjà faire, au lieu de la porter à « tout, en tant que n'importe qui ».

## Découpage en stories

| # | Story | Contenu |
|---|---|---|
| 36.1 | Fiche utilisateur : coquille, identité, rôles complets | Route `/admin/utilisateurs/{userId}`, endpoint de détail, gestion complète des rôles **dont `ROLE_ADMIN`** avec garde-fous. **Referme l'issue #250.** |
| 36.2 | Volet modération et signalements | Historique warn/suspend/ban/lift, signalements contre lui et par lui, score pondéré ; actions de modération depuis la fiche. |
| 36.3 | Volet adhésion et inscriptions | Adhésions (actives/expirées/source) et **toutes** ses inscriptions événement - vue qui n'existe nulle part. |
| 36.4 | Volet jeu | Runs personnelles (aucune surface admin aujourd'hui), participation aux sessions, succès, niveau/XP, comptes liés Discord/Steam/Twitch. |
| 36.5 | Journal d'activité du compte | Exposer les cinq audits déjà écrits et jamais lus, filtrés sur l'utilisateur, en une frise unique. |
| 36.6 | Actions ciblées sur ses objets | Relancer/arrêter sa run perso (recoupe le besoin opérationnel de #387), agir sur une inscription, forcer la vérification email, réinitialiser le mot de passe, révoquer ses sessions actives. |

Ordre conseillé : 36.1 d'abord (elle porte la coquille dont tout le reste dépend et referme #250),
puis les volets de lecture (36.2 -> 36.5) qui sont surtout de l'agrégation, et 36.6 en dernier - c'est
la seule story qui écrit sur des objets d'autrui et elle mérite d'arriver quand la fiche est déjà là
pour la contextualiser.

## Risques et points de vigilance

- **Concentration de données personnelles.** La fiche rassemble email, adhésion, historique de
  modération et activité de jeu sur un seul écran. C'est légitime pour un admin, mais ça en fait une
  surface RGPD sensible : réservée à `ROLE_ADMIN`, jamais indexable, et la page de confidentialité
  devra mentionner cet accès.
- **Dernier admin.** Toute gestion de `ROLE_ADMIN` doit rendre impossible la perte du dernier compte
  administrateur, et l'auto-rétrogradation. `AdminChangeUserRole` garde déjà le verrou
  anti-auto-modification ; il refuse aussi toute action sur un compte déjà admin, contrainte que 36.1
  doit lever **sans** perdre les deux garanties.
- **Périmètre de 36.6.** « Agir sur ses objets » peut déborder sans fin. La story doit lister les
  actions autorisées de façon fermée, chacune tracée, plutôt qu'ouvrir un accès générique.
- **Ne pas réinstrumenter.** Cinq audits existent déjà. Toute story qui croit devoir « ajouter du
  log » doit d'abord vérifier qu'elle ne redouble pas un journal existant.

## Change Log

| Date | Description |
|------|-------------|
| 2026-08-06 | Créé (draft). Issue #250 élargie en espace de contrôle utilisateur ; périmètre arbitré avec Jean (actions ciblées, pas d'usurpation ; les quatre volets) ; découpage en 6 stories. |
