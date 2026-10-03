# Story 41.4: Primes sur les items

**Status:** review
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant que joueur bloqué,
je veux offrir des pelles à qui m'enverra un item précis,
afin que les autres joueurs de la partie aient une raison d'aller le chercher en premier.

En tant que joueur,
je veux voir les primes en cours dans ma partie et toucher la prime quand j'envoie l'item,
afin d'être récompensé d'avoir aidé.

## Contexte

Deuxième puits des pelles (la commission), et une source pour qui aide. Le site reçoit déjà chaque item envoyé
dans le fil de session (`RecordSessionFeedEvent`, type `item-received`, avec l'expéditeur et le destinataire), et
marque les slots qui ont fait `!release` ou `!collect` (`session_slot.was_released`).

**Décision 7 de l'epic (Jean, 2026-10-03)** : la prime d'un slot joué à plusieurs va à son propriétaire seul.

## Critères d'acceptation

### Réglage

1. Un réglage de partie **primes en pelles** (oui/non), à côté des hints contre pelles (41.3), désactivé par défaut
   sur les trois profils, surchargeable par partie.

### Poser une prime

2. Le joueur d'un slot (même règle que l'achat d'un hint) pose une prime sur un item qu'il n'a pas encore reçu :
   montant de 10 à 1 000 pelles **en or**, débité tout de suite (motif `bounty_escrow`, clé par requête). Refusé si
   la session n'est pas en cours (409), si la partie n'a pas les primes (403), si le solde ne suffit pas (422).
   Une seule prime ouverte par item et par slot.
3. Le joueur qui a posé une prime ouverte peut la retirer : remboursement complet (motif `bounty_refund`).

### Verser une prime

4. Quand le fil enregistre l'envoi de l'item au slot de la prime, la prime est versée **au propriétaire du slot
   expéditeur**, moins une commission de **10 %** (arrondie vers le bas) qui est détruite : la ligne de séquestre
   reste, la récompense (motif `bounty_reward`, clé par prime) vaut 90 % du montant.
5. **Ne paie pas** : un envoi dont l'expéditeur ou le destinataire a fait `!release` / `!collect`, ou avait déjà
   atteint son goal au moment de l'envoi (personne ne l'a « trouvé » ; le fil ne distingue pas un release, et
   `was_released` n'est pas posé sur un slot qui a déjà son goal, cas du release automatique), un envoi du slot à lui-même, un expéditeur sans propriétaire sur le site (slot observateur
   `Bridge`, joueur inconnu), un expéditeur dont le propriétaire est celui qui a posé la prime. Dans ces cas la
   prime est **remboursée** au poseur, puisque l'item est arrivé.
6. Un item en plusieurs exemplaires : la prime porte sur le prochain exemplaire reçu.
7. Le versement est notifié au gagnant ; le poseur est notifié quand sa prime lui est rendue.

### Fin de partie

8. Une tâche planifiée (toutes les heures) rembourse les primes encore ouvertes des sessions terminées (statuts
   `finished`, `stopped`, `failed`, `crashed`) : un envoi manqué par le bridge ne bloque jamais des pelles.

### Interface

9. Fiche de slot d'une run privée :
   - dans « Items non reçus », un bouton « Prime » par item ouvre un petit formulaire (montant, confirmation) ;
   - un panneau **« Primes de la partie »** liste les primes ouvertes de la session (item, pour quel slot, montant
     versé au gagnant), visible des joueurs de la session, avec « Retirer » sur les siennes.
10. Les motifs `bounty_escrow`, `bounty_reward`, `bounty_refund` ont un libellé.

### Qualité

11. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Réglage `pelleBounties` (config de session, formulaires).
- [x] **Task 2** (AC 2, 3, 6) - Entité `ItemBounty` (Sessions), migration, pose et retrait ; tests.
- [x] **Task 3** (AC 4, 5, 7) - Règlement depuis le fil (`RecordSessionFeedEvent`), après l'enregistrement de
  l'envoi ; tests (release, collect, soi-même, sans propriétaire, poseur = gagnant, exemplaires).
- [x] **Task 4** (AC 8) - Remboursement planifié ; tests.
- [x] **Task 5** (AC 9, 10) - Front ; tests.
- [x] **Task 6** (AC 11) - Gates.

## Notes techniques

- La prime vit dans Sessions (session, slot, fil) ; les pelles bougent par `RecordPelleMovement` (Wallet).
- Le règlement suit l'enregistrement de la ligne du fil, déjà committée : un échec de versement est journalisé et
  ne casse pas la réception du fil ; la prime reste ouverte et sera remboursée en fin de partie au pire.
- Primes en or seulement : les pelles d'event servent aux hints de leur événement (41.3) ; une prime en pelles
  d'event poserait la question de leur expiration pendant le séquestre.
- Le propriétaire d'un slot : celui de `DbalSlotPlayerSource` côté propriétaire, `registration.user_id` pour une
  session d'événement, `registration_id` lui-même pour une run privée.

## Dev Agent Record

- Réglage `pelleBounties` (config de session, profils, surcharge, formulaires).
- `ItemBounty` (Sessions, table `item_bounty`, migration Version20261003150000), `ItemBountyRepositoryInterface`.
- `PostItemBounty` (séquestre et prime écrits dans la même transaction), `WithdrawItemBounty`, `SettleItemBounties`
  (appelé par `FeedPushController` après l'enregistrement de la ligne du fil, en best-effort),
  `RefundEndedSessionBounties` + message planifié à :55, `SlotOwnerResolver`, `ItemBountiesQuery`.
- Chaque changement d'état d'une prime se fait dans la fermeture transactionnelle de `RecordPelleMovement` : un
  versement refusé (gagnant banni) laisse la prime ouverte, puis elle est rendue au poseur.
- Endpoints : `GET|POST /api/v1/sessions/{id}/slots/{n}/bounties`, `DELETE /api/v1/sessions/{id}/bounties/{bountyId}`.
- Front : panneau « Primes de la partie » dans l'onglet Items de la fiche de slot d'une run privée (mêmes limites
  que la 41.3 pour les hebdos et les joueurs d'event).
