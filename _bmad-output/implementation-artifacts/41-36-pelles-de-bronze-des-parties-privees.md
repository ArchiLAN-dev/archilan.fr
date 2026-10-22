# Story 41.36: Pelles de bronze - socle, réglages et cycle de vie

**Status:** review
**Epic:** 41 - Pelles, la monnaie du site
**Date:** 2026-10-18
**Origine :** demande de Jean le 2026-10-18. Première des trois stories du bronze (41.36 socle, 41.37 gains,
41.38 dépenses et affichage).

## Story

En tant que joueur d'une partie privée,
je veux un solde de pelles de bronze propre à cette partie, qui disparaît avec elle,
afin de jouer avec une monnaie de partie sans toucher à mes pelles d'or, qui restent pour la boutique.

## Contexte

L'epic 41 prévoyait deux types de pelles : l'or (permanent, partout) et les pelles d'event (un solde par
événement, détruit à sa fin). Décisions de Jean (2026-10-18) :

- **Le bronze est propre à une partie privée** : un solde par joueur et par partie, sans transfert entre parties.
- **Jamais converti**, ni en or ni en pelles d'event, dans aucun sens.
- **Brûlé à la fin de la partie.**
- **En partie privée, seul le bronze paie** (hints, primes) ; l'or ne sert qu'à la boutique (41.38).
- **Gains** : dotation de départ, par check, au goal, chacun réglé par le propriétaire avec une valeur par
  défaut ; 0 coupe la source (41.37).
- **Hebdos et events hors périmètre** : inchangés.

Cette story pose ce que les deux suivantes utilisent : le type, le rattachement à la partie, les réglages, et la
fin de vie du solde.

Constats de la relecture (2026-10-18) :

- Une partie **annulée peut être restaurée** (`Run::unarchive`, retour en `idle` si elle avait été lancée, story
  16.21). Brûler à l'annulation sans rien prévoir ferait perdre le bronze d'une partie qu'on restaure.
- La fin de partie n'est pas la fin de session : une partie en pause a une session arrêtée
  (`RefundEndedSessionBounties` traite déjà `stopped` comme terminée). Le brûlage se règle sur le **statut de la
  partie**, jamais sur celui de la session.

## Critères d'acceptation

1. **Type `bronze`.** `PelleKind::Bronze` ; `pelle_movement` gagne une colonne `run_id` nullable, indexée avec
   `user_id`. Le solde de bronze se lit par (membre, partie). `RecordPelleMovement`, seul point d'écriture, refuse
   un mouvement de bronze sans partie, et une partie sur un mouvement d'un autre type. Ses règles existantes
   valent pour le bronze : verrou, solde jamais négatif, clé anti-doublon, compte banni.
2. **Motifs** : `bronze_start`, `bronze_check`, `bronze_goal` (41.37), `bronze_expired`, `bronze_restored`
   (cette story). Les motifs de hint et de prime existants servent aussi pour le bronze.
3. **Réglages de partie.** `SessionServerConfig` gagne trois réglages du site, jamais envoyés à Archipelago
   (comme `pelleHints`) : dotation de départ, pelles par check, pelles au goal, chacun de 0 à 1000. Ils suivent
   le modèle de l'epic 27 : l'admin règle les défauts du profil « private », le propriétaire les surcharge pour
   sa partie. Défauts proposés : départ 20, check 1, goal 25 (Jean ajuste). Sur les profils « weekly » et
   « event », ces réglages n'apparaissent pas.
4. **Formulaire.** La config de la partie privée (propriétaire) et celle du profil « private » (admin) montrent
   les trois montants, avec une phrase : « 0 coupe ce gain ».
5. **Fin de partie.** Quand une partie passe à `completed` ou `cancelled` :
   - les primes encore ouvertes de sa session sont d'abord remboursées (dans leur type, voir 41.38) ;
   - puis le solde de bronze restant de chaque joueur de la partie est détruit par un mouvement
     `bronze_expired`, une ligne par joueur au solde positif.
   C'est un mouvement, jamais un filtre, pour que le registre et les statistiques restent justes.
6. **Restauration.** Si une partie annulée est restaurée, chaque joueur retrouve exactement ce qui a été brûlé à
   l'annulation (mouvement `bronze_restored`, montant inverse). Une partie annulée puis restaurée plusieurs fois
   reste juste : les clés anti-doublon portent le numéro du passage (par exemple
   `bronze-expired:{run}:{membre}:{n}`), jamais une clé qui bloquerait le deuxième passage.
7. **Lecture** : déplacée en 41.38, qui la consomme (pas de requête sans lecteur).
8. **Tests** : unitaires (bronze sans partie refusé, partie sur un autre type refusée, soldes séparés par partie,
   bornes et défauts des réglages) ; fonctionnels (brûlage à la complétion, à l'annulation, après les primes ;
   restauration qui rend le montant ; annuler, restaurer, annuler, restaurer reste juste ; une pause ne brûle
   rien).
9. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - Enum, colonne `run_id` + index (migration réversible), règles dans
  `RecordPelleMovement`, solde par partie dans `PelleMovementRepositoryInterface`.
- [x] **Task 2** (AC 3, 4) - Réglages dans `SessionServerConfig` / `SessionConfigOverride` / `SessionConfig`
  (sérialisation, override, bornes) ; formulaires front propriétaire et admin.
- [x] **Task 3** (AC 5, 6) - Brûlage et restauration branchés sur `PersonalRunLifecycle::complete`, l'annulation
  et la restauration de `PersonalRunDrafts`, après la transaction de la partie (effet de bord post-commit).
- [x] **Task 4** (AC 7) - Déplacée en 41.38.
- [x] **Task 5** (AC 8, 9) - Tests et gates.

## Dev Notes

- Mettre à jour `planning-artifacts/epics/epic-41-pelles-monnaie-du-site.md` (Principes) : « deux types de
  pelles seulement » devient trois, avec la règle du bronze.
- Le solde de bronze est privé, comme l'or : visible du membre et des admins seulement.
- Pas d'affichage joueur dans cette story (41.38) ; elle se vérifie par les tests et la base.

## Dev Agent Record

- **Un réconciliateur plutôt que deux réactions.** `ReconcileRunBronze::reconcile()` regarde le statut de la
  partie au moment où il tourne : terminée ou annulée, il brûle les soldes positifs ; jouable, il rend chaque
  brûlage pas encore rendu. L'ordre des jobs n'a donc aucune importance : annulée puis restaurée avant que le
  worker passe, les deux jobs voient une partie jouable sans brûlage, et rien ne bouge. Relancé, il ne refait rien.
- **Clés à rang** : `bronze-expired:{run}:{membre}:{n}` et `bronze-restored:{run}:{membre}:{n}`, n compté dans
  le registre. Le second brûlage d'une partie annulée deux fois n'est pas refusé par la clé du premier.
- **La restitution rend un total, jamais une ligne précise** : « brûlé » moins « déjà rendu », en un mouvement.
  Les dates du registre sont à la seconde : rendre le n-ième brûlage selon l'ordre des lignes rendait parfois le
  mauvais montant (deux brûlages dans la même seconde), trouvé par la suite complète.
- **Port `RunBronzeInterface`** (PersonalRuns) implémenté par `LedgerRunBronze` (Wallet) :
  `reconcileLater()` envoie `ReconcileRunBronzeJob` (async) après le `flush()` de la partie ; `burnNow()` brûle
  tout de suite, juste avant une suppression définitive, tant que la partie existe pour le registre
  (`RecordPelleMovement` refuse une partie introuvable).
- Brûlage et restitution passent `byAdmin: true`, comme l'expiration des pelles d'event : c'est le système qui
  agit, un compte banni est concerné aussi. Un compte supprimé est sauté (log `wallet.bronze.reconcile_skipped`).
- Les réglages (20 / 1 / 25) existent sur les trois profils côté valeur, mais le front ne les montre que pour le
  profil « private » et le formulaire de surcharge d'une partie privée (`showBronze`).
- Migration `Version20261018100000` (`pelle_movement.run_id` + index `(run_id, user_id)`).

### Tests

- `PelleMovementTest` : bronze rattaché à une partie ; seul le bronze nomme une partie (4 cas refusés).
- `PelleHintSettingsTest` : défauts 20/1/25, surcharge (0 coupe), profil ancien, borne 1000, absents des flags.
- `RunBronzeTest` (fonctionnel) : soldes séparés par partie ; partie inexistante refusée ; annulation qui brûle ;
  restauration qui rend, deux fois de suite ; annulée puis restaurée avant le worker sans mouvement ; complétée
  brûle, en pause non, relancé sans doublon ; banni brûlé ; suppression définitive qui brûle d'abord.
