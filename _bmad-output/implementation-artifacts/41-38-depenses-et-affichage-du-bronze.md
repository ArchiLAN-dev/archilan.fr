# Story 41.38: Pelles de bronze - dépenses et affichage

**Status:** review
**Epic:** 41 - Pelles, la monnaie du site
**Date:** 2026-10-18
**Dépend de :** 41.36 (socle), 41.37 (gains).

## Story

En tant que joueur d'une partie privée,
je veux payer hints et primes avec le bronze de cette partie et voir où j'en suis,
afin que mes pelles d'or restent pour la boutique.

## Contexte

Aujourd'hui, en partie privée, hints (41.3) et primes (41.4) se paient en or : `PelleHintTerms` ne donne aucun
`eventId` à une session de partie, `BuyHintWithPelles::purse()` débite donc l'or, et `PostItemBounty`,
`SettleItemBounties`, `RefundEndedSessionBounties` sont en or partout.

Décision de Jean : en partie privée, **seul le bronze paie**, l'or ne sert qu'à la boutique. Les primes y passent
en bronze, par cohérence avec les hints (expliquées à Jean le 2026-10-18 : un joueur promet des pelles à qui lui
enverra un objet ; 10 % de commission détruits ; remboursée si personne ne l'envoie).

Constat de la relecture : au déploiement, des primes **en or** peuvent être ouvertes dans des parties privées en
cours. Leur versement et leur remboursement doivent suivre le type de leur mise, pas la règle du moment, sinon un
séquestre en or serait rendu en bronze (or détruit, bronze créé).

## Critères d'acceptation

1. **Hint en partie privée** : débite uniquement le bronze de cette partie (`hint_purchase`, `run_id`). Solde de
   bronze insuffisant : refus `insufficient_pelles` avec le prix et le solde de bronze, **même si le joueur a de
   l'or**. Un hint non donné est remboursé en bronze de la même partie.
2. **Prime en partie privée** : la mise est débitée en bronze de la partie ; le versement (au propriétaire du slot
   expéditeur, décision 7, moins 10 %) et le remboursement se font en bronze de la même partie.
3. **Une prime suit le type de sa mise.** Versement et remboursement lisent le type et la partie (ou l'event) du
   mouvement de séquestre : une prime posée en or avant le déploiement est versée ou rendue en or.
4. **Ordre de fin de partie** (41.36 AC 5) : les primes ouvertes sont remboursées avant le brûlage, pour que le
   bronze rendu soit ensuite brûlé et que l'or rendu reste.
5. **Hebdos et events inchangés** : hints et primes s'y paient comme avant (pelles d'event puis or en event, or en
   hebdo). Les tests existants restent verts sans changer leurs attentes.
6. **Page de la partie et fiche de slot** : le joueur voit son solde de bronze de cette partie, les gains réglés
   (départ, par check, au goal) et les prix des hints. Le bouton de hint et le formulaire de prime affichent le
   bronze, jamais l'or, en partie privée. Un joueur sans bronze voit pourquoi il ne peut pas acheter.
6bis. **Lecture** (reprise de 41.36 AC 7) : une requête donne, pour un membre et une partie, le solde de bronze et
   l'historique de bronze de cette partie ; une autre, ses soldes de bronze sur ses parties en cours.
7. **`/compte/pelles`** : une section « Bronze » liste les parties en cours avec leur solde et un historique par
   partie où les crédits par check sont regroupés (par exemple « 37 checks, +37 » par jour), et rappelle que le
   bronze disparaît à la fin de la partie. Le solde d'or et son historique ne contiennent aucun mouvement de
   bronze.
8. **Fiche admin d'un membre** : le bloc Pelles montre aussi ses soldes de bronze par partie en cours (lecture
   seule ; pas de crédit ni débit admin de bronze dans cette story).
9. **Statistiques admin** (`/admin/statistiques#pelles`) : la circulation d'or n'inclut pas le bronze ; une ligne
   à part donne le bronze créé, dépensé et brûlé sur la période.
10. **Notifications** : aucune notification par check ; le versement et le remboursement de prime gardent leurs
    notifications, avec le bon type de pelle dans le texte.
11. **Tests** : fonctionnels (hint et prime en bronze sans toucher l'or ; refus sans bronze malgré de l'or ;
    prime en or ouverte avant le changement versée et rendue en or ; ordre remboursement puis brûlage ; hebdo et
    event inchangés) ; front (solde et prix en bronze, historique regroupé, section absente sans partie en
    cours).
12. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - `PelleHintTerms` expose la partie ; `BuyHintWithPelles::purse()` choisit le bronze en
  partie privée, sans repli sur l'or.
- [x] **Task 2** (AC 2-4) - `PostItemBounty`, `SettleItemBounties`, `WithdrawItemBounty`,
  `RefundEndedSessionBounties` : type et portée lus sur le séquestre.
- [x] **Task 3** (AC 6) - API et front de la page de partie et de la fiche de slot.
- [x] **Task 4** (AC 7, 8) - `/compte/pelles` et fiche admin.
- [x] **Task 5** (AC 9, 10) - Statistiques et textes de notification.
- [x] **Task 6** (AC 11, 12) - Tests et gates.

## Dev Notes

- Le hint en pelles reste désactivé par défaut en partie privée (décision 2 de l'epic), et les primes aussi : le
  bronze se gagne même si les deux sont coupés. À rediscuter avec Jean : activer les deux par défaut maintenant
  qu'ils ne coûtent plus d'or.
- Appliquer « l'or ne sert qu'à la boutique » aux hebdos et aux events est une story à part, à écrire quand Jean
  l'aura tranché.

## Dev Agent Record

- **Hints** : `PelleHintTerms` expose `runId` ; `BuyHintWithPelles::purse()` ne prend que le bronze de la partie,
  sans repli sur l'or (refus `insufficient_pelles`, détails `{price, bronze}`) ; remboursement en bronze. L'offre
  (`GET .../pelle-hints`) gagne `bronzeBalance` (null hors partie privée, null aussi pour les hebdos).
- **Primes** : `item_bounty.run_id` (migration `Version20261018110000`). Une prime d'une partie privée tient son
  bronze ; une prime sans partie (toutes celles d'avant) tient de l'or. `BountyPurse` construit chaque mouvement
  (séquestre, versement, retrait, remboursement) d'après la prime elle-même, jamais d'après la règle du moment.
- **Fin de partie** : `ReconcileRunBronze::burn()` rembourse d'abord les primes ouvertes de la session
  (`RefundEndedSessionBounties::refundSession()`), puis brûle.
- **Lecture** : `WalletQueryInterface` gagne `bronze` (par partie, solde non nul) et son historique exclut le
  bronze ; `RunBronzeQueryInterface` + `MyRunBronze` pour `GET /api/v1/runs/{runId}/bronze` (solde, gains et prix
  réglés, historique avec les checks regroupés par jour).
- **Stats** : `bronze: {created, spent, burned}` sur la période ; l'or en circulation n'a jamais compté le bronze
  (requêtes déjà filtrées sur `kind = gold`).
- **Front** : carte « Mes pelles de bronze » sur la page de la partie (`RunBronzeCard`), section bronze sur
  `/compte/pelles` et la fiche admin, prix des hints affiché « pelles de bronze » en partie privée, chiffres bronze
  dans les statistiques, aides de config mises à jour.
- **Tests existants adaptés** : `PelleHintTest` et `ItemBountyTest` montaient une partie privée payée en or ; ils
  paient désormais en bronze (c'est la règle de la story). `ItemBountyTest` coupe le gain par check (41.37) pour
  garder des sommes de primes lisibles. Les tests d'event et d'hebdo gardent leurs attentes (l'hebdo gagne
  seulement `bronzeBalance: null` dans l'offre).

### Tests

- `PelleHintTest` : hint payé en bronze sans toucher l'or ; refusé sans bronze malgré 500 d'or ; remboursé en
  bronze ; offre avec `bronzeBalance`.
- `ItemBountyTest` : prime posée avant la story payée en or ; fin de partie qui rend la prime puis brûle tout.
- `RunBronzeTest` : portefeuille (bronze à part, historique sans bronze), endpoint de partie (checks regroupés),
  statistiques (bronze à part de l'or).
- Front : `run-bronze.test.tsx`, `pelle-hints.test.tsx` (bronze seul en partie privée), `wallet-views.test.tsx`.
