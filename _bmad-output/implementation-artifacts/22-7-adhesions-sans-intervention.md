# Story 22.7: Une adhésion payée s'applique sans intervention

**Status:** review
**Epic:** 22 - Adhésions
**Date:** 2026-09-27
**Origine :** signalement de Jean le 2026-09-27 - les adhésions ont du mal à s'appliquer automatiquement ; même
après 24 h, il faut actualiser à la main.

## Story

En tant que membre qui paie sa cotisation sur HelloAsso,
je veux que mon adhésion s'applique d'elle-même, une seule fois, et que l'espace compte me montre membre,
afin de ne dépendre d'aucune action d'un admin.

## Contexte (revue du 2026-09-27)

Le seul déclencheur automatique est le webhook HelloAsso (`HandleHelloAssoWebhook`). Aucune synchronisation n'est
planifiée : une commande manquée n'est rattrapée que par le bouton admin « Synchroniser HelloAsso »
(`POST /api/v1/admin/helloasso/sync`). Or le webhook abandonne en silence, et répond 200 donc HelloAsso ne
réessaie pas, dans plusieurs cas :

| # | Défaut | Effet |
|---|---|---|
| 1 | aucune synchro planifiée | une commande manquée par le webhook attend un clic admin, indéfiniment |
| 2 | si la vérification de la commande échoue (token, 401/404, délai), le webhook s'arrête **avant** de lancer la synchro du formulaire | rien n'est rattrapé, pas même par la synchro asynchrone et ses reprises |
| 3 | `ActivateMembership` ne vérifie pas qu'une commande a déjà été appliquée | le webhook puis la synchro qui le suit prolongent deux fois (24 mois) ; une commande déjà portée par une adhésion expirée lève une violation d'unicité qui ferme l'EntityManager et perd les activations suivantes de la même synchro |
| 4 | la synchro émet « payé » dès qu'une commande a une date, quel que soit son état | une commande remboursée, annulée ou contestée peut activer une adhésion |
| 5 | un webhook de type adhésion sur un autre formulaire que `HELLOASSO_MEMBERSHIP_FORM_SLUG` est ignoré sans trace | formulaire renommé ou nouvelle saison : plus rien ne s'applique, sans aucun log |
| 6 | l'espace compte affiche « Membre » d'après le rôle `ROLE_MEMBER`, qu'un paiement n'attribue jamais | un membre payé lit « Utilisateur » (les accès, eux, lisent la table des adhésions et fonctionnent) |

## Critères d'acceptation

1. **Synchro de secours** : toutes les heures (à :10), la synchro du formulaire d'adhésion est lancée
   (`SyncHelloAssoMembershipFormMessage` → `SyncHelloAssoFormMessage`) ; sans formulaire configuré, elle est sautée
   avec un log.
2. **Le webhook lance toujours la synchro** du formulaire, même quand la vérification de la commande échoue.
3. **Idempotence** : une commande HelloAsso déjà portée par une adhésion (active ou expirée) n'est pas réappliquée ;
   ni prolongation, ni notification, log `membership.order_already_applied`.
4. **Paiement confirmé** : la synchro n'émet « payé » que pour une commande à l'état `Processed` avec une date, et
   une seule fois (à la transition vers cet état).
5. **Log explicite** : un webhook d'adhésion sur un formulaire autre que celui configuré est journalisé en `warning`.
6. **Libellé** : l'espace compte affiche « Membre » d'après l'adhésion active, plus d'après le rôle.
7. `composer gates` et `pnpm gates` passent.

## Hors périmètre

- Authentifier le webhook HelloAsso (signature) : la relecture de la commande par l'API limite déjà l'effet.
- Le texte de la page de cotisation (« Un administrateur confirmera ton statut de membre ») contredit l'activation
  automatique : à trancher avec le bureau avant de le changer.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Message planifié et son handler.
- [x] **Task 2** (AC 2, 5) - Webhook.
- [x] **Task 3** (AC 3) - Idempotence d'`ActivateMembership`.
- [x] **Task 4** (AC 4) - État `Processed` dans la synchro.
- [x] **Task 5** (AC 6) - Libellé de l'espace compte.
- [x] **Task 6** (AC 7) - Gates.

## Dev Agent Record

- **Synchro de secours** : `SyncHelloAssoMembershipFormMessage` planifié à `10 * * * *` ; son handler relaie
  `SyncHelloAssoFormMessage` (async, repris par Messenger) sur le formulaire configuré, ou saute avec un log.
- **Webhook** : la vérification de la commande est extraite (`verify()`) ; la synchro du formulaire part dans tous
  les cas, le message « payé » seulement si la commande est vérifiée. Un webhook d'adhésion sur un autre formulaire
  que `HELLOASSO_MEMBERSHIP_FORM_SLUG` est journalisé `helloasso.webhook.membership_form_mismatch`.
- **Idempotence** : `MembershipRepositoryInterface::findByHelloassoOrderId` ; `ActivateMembership` sort avec
  `membership.order_already_applied` si une adhésion, active ou expirée, porte déjà la commande. Limite connue : la
  colonne ne garde que la dernière commande d'une adhésion renouvelée ; une commande ancienne ne revient pas par la
  synchro (elle n'émet « payé » qu'une fois par commande), seulement par une réconciliation admin volontaire.
- **Paiement confirmé** : `SyncHelloAssoFormHandler::isPaid` exige `Processed` et une date, à l'émission comme pour
  savoir si la commande l'était déjà.
- **Libellé** : `accountRoleLabel` (front) lit l'adhésion active (clé `account-membership`, partagée avec la section
  adhésion), et non plus le rôle `ROLE_MEMBER`.

### Déroulé TDD

| Étape | Rouge | Vert |
|---|---|---|
| `HelloAssoSyncHandlerTest` : commande datée remboursée, annulée, contestée, en attente ; passage à `Processed` signalé une fois | 5 échecs | verts |
| `ActivateMembershipTest` : commande déjà appliquée | prolongée à nouveau | ignorée |
| `HandleHelloAssoWebhookTest` : vérification en échec, commande introuvable, formulaire d'adhésion différent | 3 échecs | verts |
| `SyncHelloAssoMembershipFormMessageHandlerTest`, `ScheduleTest` | classes absentes | verts |
| `account-role.test.ts` | module absent | verts |

### Vérifications

- `composer gates` vert (2299 tests), `pnpm gates` vert (557 tests).
