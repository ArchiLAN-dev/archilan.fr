# Story 41.54: Jouer au blind test sans être inscrit à l'événement

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôt :** `archilan.fr` (site). L'app du blind test ne change pas : elle fait confiance au site pour l'entrée et
les crédits.
**Origine :** Jean le 2026-10-25 : les autres joueurs (non inscrits à l'événement) doivent pouvoir faire le blind test,
et gagner quand même les pelles de leurs trouvailles. Le plafond s'applique, et un interrupteur admin coupe les gains des
non-inscrits.

## Story

En tant que membre d'archilan.fr présent à l'événement sans y être inscrit,
je veux entrer dans le blind test et gagner les pelles de mes trouvailles,
afin de participer à l'animation comme les inscrits.

## Critères d'acceptation

1. **Entrée** : tout compte connecté peut entrer pendant l'événement, inscrit ou non. Cela vaut pour le bouton et le
   QR code de la page d'événement, la création du code de connexion et l'échange. Les autres règles ne changent pas :
   le blind test doit être ouvert et configuré, et l'événement en cours.
2. **Crédits** : une trouvaille d'un non-inscrit est créditée comme celle d'un inscrit. Elle rapporte les pelles
   d'événement au taux de l'événement, sous le même plafond par joueur (compté avant conversion) et avec la même
   idempotence. Un compte banni reste refusé par le registre (`RecordPelleMovement`), comme avant.
3. **Fin d'événement** : les pelles d'événement d'un non-inscrit suivent la règle commune. 10 % sont convertis en or
   (une écriture par membre), le reste est détruit (`endedEventBalances` ne regarde pas l'inscription). Une conversion
   au fil de l'eau, par blocs de 10, a été écrite puis retirée le 2026-10-25 : trop d'écritures au registre.
4. **Interrupteur** : réglage `pellesForUnregistered` du blind test de l'événement, actif par défaut (colonne
   `blind_test_setting.pelles_for_unregistered`, migration `Version20261025200000`). Coupé, un non-inscrit joue pour le
   classement seulement : ses crédits sont refusés (403 `unregistered_without_pelles`). Laissé hors d'un PUT, il garde
   sa valeur.
5. **Admin, pelles de l'événement** : la liste montre les inscrits actifs, plus tout membre qui détient ou a détenu
   des pelles de cet événement. Chaque ligne porte `registered`.
   - Un non-inscrit est marqué « non inscrit ».
   - Il n'entre pas dans « Tous les inscrits » et ne peut pas être coché pour une distribution : la distribution
     reste réservée aux inscrits (`DistributeEventPelles` inchangé).
   - Le titre de la section devient « Membres (n) ».
6. Le code d'erreur `not_registered` du blind test disparaît. Celui de la distribution reste.

## Tâches

- [x] `BlindTestGate::open` sans contrôle d'inscription (et sans le paramètre membre devenu inutile).
- [x] `CreditBlindTestSource` : refus des non-inscrits quand leurs gains sont coupés.
- [x] Réglage `pellesForUnregistered` (entité, migration, service, contrôleur, carte admin).
- [x] `DbalEventPellesQuery::eventPage` : inscrits actifs, plus les détenteurs de pelles de l'événement, avec
  `registered`.
- [x] Front : type `EventPelleParticipant.registered`, éligibles = inscrits non bannis, mention « non inscrit ».
- [x] Tests : `BlindTestTest` (un non-inscrit entre, reçoit un code, est crédité en pelles
  d'événement, ne gagne rien quand ses gains sont coupés) ; `EventPellesTest` ; tests front.
- [x] Gates des deux côtés.

## Dev Agent Record

- Les tests front qui dépendent de l'adresse du site (sitemap, robots, données structurées) échouent avec un
  `frontend/.env` pointé sur l'IP du réseau local. Ils passent avec les adresses par défaut, comme en CI.
- L'app du blind test n'est pas modifiée. Sa console compte les refus (`unregistered_without_pelles` compris) parmi
  les « crédits refusés ».
- Migration appliquée à la base de dev locale.

## Hors périmètre

- Proposer « Connecte-toi pour jouer » aux visiteurs anonymes sur la page d'événement.
- Dépenser ses pelles d'événement sans être inscrit : les indices passent par un slot, donc par une inscription. Elles
  ne servent qu'à la conversion de fin d'événement.
