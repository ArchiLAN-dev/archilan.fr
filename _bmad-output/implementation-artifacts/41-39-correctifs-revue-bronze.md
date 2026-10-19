# Story 41.39: Correctifs de la revue des pelles de bronze (41.36 à 41.38)

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-19
**Origine :** revue des PR #865 (41.36), #866 (41.37) et #867 (41.38) du 2026-10-18, demandée par Jean.

## Story

En tant que joueur d'une partie privée,
je veux que mes pelles de bronze soient gagnées, brûlées et dépensées sans trou ni double compte,
afin que le bronze reste une monnaie de partie fiable, sans fuite ni création de pelles.

## Contexte

La revue a trouvé cinq défauts vérifiés dans le code et six risques. Tous portent sur du code des stories 41.36 à
41.38, pas encore mergé : cette story part de la branche de la 41.38 et sa PR s'empile sur la #867.

## Acceptance Criteria

1. **Toute fin de partie brûle le bronze.** Les deux chemins qui terminent une partie sans passer par
   `PersonalRunLifecycle` la réconcilient aussi (`RunBronzeInterface::reconcileLater`) : le filet des runs coincées
   (`ReconcileStuckRunsHandler`, session terminée → partie terminée ; session en cours → partie active, ce qui
   donne aussi le bronze de départ) et la commande `RepairFinishedRunsCommand`. Une run remise en brouillon ne
   déclenche rien.
2. **Pas de bronze de départ sur une partie non lancée.** `ReconcileRunBronze` ne donne le départ qu'à une partie
   lancée et jouable (active, à l'arrêt, en arrêt ou en redémarrage), jamais à un brouillon ni à une partie en
   cours de génération, même si elle garde un `sessionId` d'une tentative précédente.
3. **Pas de gain après la fin.** `CreditRunBronze` ne crédite rien (check ni objectif) sur une partie terminée ou
   annulée : son bronze a déjà brûlé.
4. **Le goal du receveur ne coupe plus le gain de l'envoyeur.** Un check joué par l'envoyeur paie même si le slot
   receveur a atteint son objectif ; seuls un release ou un collect du receveur (ou de l'envoyeur, ou l'objectif de
   l'envoyeur) l'excluent.
5. **Le gain d'objectif est rejoué.** Un callback d'objectif répété (déjà enregistré) retente le crédit de bronze :
   ses clés le rendent idempotent, et un premier crédit en échec n'est plus perdu.
6. **Montants bornés à l'écriture.** Un override de session refuse (`422`) un prix d'indice hors 1 à 1000
   (`invalid_pelle_hint_price`) et un montant de bronze hors 0 à 1000 (`invalid_bronze_amount`), au lieu de
   l'enregistrer et de casser ensuite la résolution de la configuration de la partie.
7. **Bronze d'une partie : ses joueurs seulement.** `GET /api/v1/runs/{runId}/bronze` répond `404` à qui n'est ni
   propriétaire, ni participant, ni co-joueur d'un slot de la partie.
8. **Une prime ne se règle qu'une fois.** Paiement, remboursement (arrivée, fin de partie) et retrait verrouillent la
   ligne de la prime dans la transaction du mouvement et vérifient qu'elle est encore ouverte : deux règlements
   concurrents ne peuvent plus payer le gagnant et rembourser l'auteur.
9. **Suppression d'une partie.** Si le bronze restant ne peut pas brûler, la suppression est refusée proprement
   (`blockReason: bronze_burn_failed`, loggée) au lieu d'une erreur 500 ; supprimer quand même laisserait du bronze
   orphelin.
10. **Front.** Le formulaire de prime d'une partie privée parle de « pelles de bronze » et rafraîchit le solde de
    bronze (`["run-bronze"]`) après une prime posée ou retirée ; l'API des primes dit si la session paie en bronze.
    Dans le formulaire d'override, cocher « surcharger » part de la valeur héritée du profil, pas d'un `false` figé.
11. **Tests** pour chaque point, `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] AC 1 : `ReconcileStuckRunsHandler`, `RepairFinishedRunsCommand` + tests
- [x] AC 2 : garde de statut dans `ReconcileRunBronze::grantStart` + test
- [x] AC 3, 4 : `CreditRunBronze` (partie terminée, receveur) + `SessionSlot::handedOverBy` + tests
- [x] AC 5 : `RecordSlotGoal` rejoue le crédit + test
- [x] AC 6 : `SessionConfigOverride::fromArray` + tests
- [x] AC 7 : `MyRunBronze` + test fonctionnel
- [x] AC 8 : `ItemBountyRepositoryInterface::claimOpen` + les quatre règlements + tests
- [x] AC 9 : `PersonalRunDrafts::delete` + test
- [x] AC 10 : front (primes, override) + tests
- [x] Gates

## Dev Agent Record

### Implementation Notes

- AC 1 : les deux filets réconcilient après leur `flush()`, comme les autres chemins (`reconcileLater`, donc depuis
  le worker). Le filet des runs coincées réconcilie aussi une run ramenée à `active` : elle reçoit son départ, ce que
  les autres `markRunning` hors du chemin principal ne font toujours pas (non signalé par la revue, laissé tel quel).
- AC 4 : `SessionSlot::handedOverBy($at)` (release, forfait ou collect) remplace `stoppedFinding` côté receveur dans
  `CreditRunBronze` seulement. Les primes (story 41.4) gardent leur règle : une prime pour un slot qui a fini est
  rendue à son auteur, ce qui reste cohérent.
- AC 8 : `claimOpen` est un `UPDATE item_bounty SET status = status WHERE id = :id AND status = 'open'` exécuté dans la
  transaction du mouvement : il prend le verrou de ligne, et Postgres relit le statut une fois le verrou obtenu. Le
  second règlement trouve la prime close, lève `BountyAlreadySettledException` (ou `ConflictException` pour un
  retrait) et son mouvement est annulé. Un paiement qui perd la course ne rembourse pas non plus.
- AC 9 : le bronze brûle avant la trace d'audit, pour ne pas tracer une suppression qui n'a pas eu lieu.
- AC 10 : `ItemBountiesQuery` renvoie `bronze` ; le formulaire d'override part de la valeur héritée
  (`initialOverrideValue`).

### File List

- `api/src/PersonalRuns/Application/Handler/ReconcileStuckRunsHandler.php`,
  `api/src/PersonalRuns/Presentation/Command/RepairFinishedRunsCommand.php`,
  `api/src/PersonalRuns/Application/Service/PersonalRunDrafts.php`
- `api/src/Wallet/Application/Command/ReconcileRunBronze.php`, `api/src/Wallet/Application/Query/MyRunBronze.php`
- `api/src/Sessions/Application/Command/CreditRunBronze.php`, `RecordSlotGoal.php`, `SettleItemBounties.php`,
  `RefundEndedSessionBounties.php`, `WithdrawItemBounty.php`, `api/src/Sessions/Application/Query/ItemBountiesQuery.php`,
  `api/src/Sessions/Application/Exception/BountyAlreadySettledException.php` (nouveau),
  `api/src/Sessions/Domain/Entity/SessionSlot.php`, `api/src/Sessions/Domain/Repository/ItemBountyRepositoryInterface.php`,
  `api/src/Sessions/Infrastructure/Doctrine/DoctrineItemBountyRepository.php`
- `api/src/SessionConfig/Domain/ValueObject/SessionConfigOverride.php`
- Tests : `api/tests/Unit/PersonalRuns/ReconcileStuckRunsHandlerTest.php`, `SpyRunBronze.php` (nouveau),
  `PersonalRunDraftsDeleteBronzeTest.php` (nouveau), `PersonalRunDraftsGetTest.php`, `PersonalRunDraftsListMineTest.php`,
  `api/tests/Unit/SessionConfig/SessionConfigOverrideBoundsTest.php` (nouveau),
  `api/tests/Functional/RunBronzeEarningsTest.php`, `RunBronzeTest.php`, `ItemBountyTest.php`
- `frontend/src/features/wallet/item-bounties.tsx`, `item-bounties.test.tsx`,
  `frontend/src/features/admin/session-config-override-form.tsx`, `session-config-override-form.test.ts` (nouveau)
