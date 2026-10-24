# Story 41.40: Pelles d'event gagnées au blind test de mashups

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-24
**Origine :** demande de Jean le 2026-10-24 : un blind test de mashups (façon SiIvaGunner) tourne 48 h dans la salle
pendant le prochain event. Chaque source trouvée rapporte automatiquement des pelles d'event. Le jeu lui-même est une
app séparée (note d'architecture : https://claude.ai/artifact/89BdZXDv3WwaKzozJpauR4) ; cette story couvre seulement
le côté archilan.fr.

## Story

En tant qu'inscrit à un event,
je veux rejoindre le blind test depuis la page de l'event et voir mes bonnes réponses créditées en pelles d'event,
afin de jouer sans compte à part et sans attendre une distribution manuelle.

En tant qu'admin d'un event,
je veux activer le blind test et régler ce qu'il rapporte,
afin de garder la main sur le budget de pelles.

## Contexte

- `RecordPelleMovement` est le seul point d'écriture du registre (41.1) : solde jamais négatif, `uniqueKey` écrit une
  seule fois, banni refusé hors action admin. Les pelles d'event (`PelleKind::Event`, `eventId`) expirent avec l'event
  (`ExpireEventPelles`).
- Les secrets HMAC existants suivent le même modèle (`OrchestratorWebhookController`, `ModerationContactPass`).
- L'app de blind test ne lit pas la base du site : elle reçoit un jeton signé et renvoie des crédits signés.

## Critères d'acceptation

1. **Réglages par event.** Dans l'admin d'un event (onglet des pelles), un bloc « Blind test » : activé (défaut non),
   pelles par source (défaut 1, de 1 à 100), plafond par joueur (défaut 50, de 1 à 10 000). Stockés côté Wallet,
   par `eventId`, sans toucher l'entité `Event`.
2. **Lien de participation.** `GET /api/v1/events/{eventId}/blind-test/join` (auth) : pour un inscrit actif d'un event
   en cours dont le blind test est activé, renvoie `{url}` = `BLIND_TEST_APP_URL` + un jeton
   `{userId, eventId, displayName, exp}` signé HMAC-SHA256 (`BLIND_TEST_SHARED_SECRET`), `exp` = fin de l'event.
   Sinon 403 avec un code (`blind_test_disabled`, `not_registered`, `event_not_running`). La page publique de l'event
   montre « Jouer au blind test » dans ces seuls cas.
3. **Crédit.** `POST /api/v1/integrations/blind-test/credits`, sans session utilisateur, authentifié par
   `X-Archilan-Timestamp` et `X-Archilan-Signature` (HMAC-SHA256 de `{timestamp}.{corps}`, écart maximal 5 min,
   comparaison à temps constant). Corps : `eventId`, `userId`, `uniqueKey` (préfixe `blindtest:` obligatoire, 120
   caractères max, la taille de la colonne du registre), `track`, `source` (libellés pour l'historique). Le montant vient des réglages, jamais du corps.
4. **Réponses du crédit.** 201 `{credited, balance}` ; 200 si la clé est déjà connue (`credited` 0, rien d'écrit) ;
   401 signature ou horodatage invalide ; 403 `blind_test_disabled`, `event_not_running`, `not_registered`,
   `member_banned` ; 409 `cap_reached` quand le total blind test du joueur sur l'event plus le montant dépasse le
   plafond ; 422 corps invalide ; 503 `blind_test_unconfigured` sans secret ou sans adresse d'app.
5. **Registre.** Chaque crédit est un mouvement `PelleKind::Event` de l'event, motif nouveau `PelleReason::BlindTest`,
   libellé « Blind test : {source} », `uniqueKey` = la clé reçue, via `RecordPelleMovement`. Le plafond est vérifié
   dans la même transaction que l'écriture (deux crédits simultanés ne le dépassent pas).
6. **Historique et correction.** Les crédits apparaissent dans l'historique de pelles du joueur et de l'admin avec
   leur libellé. Annuler des crédits passe par l'ajustement admin existant (`AdjustMemberPelles`), pas de nouvel outil.
7. **Interrupteur.** Désactiver le blind test d'un event fait refuser tout nouveau crédit et tout nouveau lien
   immédiatement ; les pelles déjà créditées restent.
8. **Hors périmètre.** L'app de blind test elle-même, l'import de playlist, la notification par crédit (trop
   fréquente : pas de `Notifier` ici), les joueurs à distance.

## Tâches

- [x] Domaine Wallet : `PelleReason::BlindTest`, entité de réglages par event + repository + migration.
- [x] Application : `BlindTestSettings` (lecture/écriture admin), `IssueBlindTestJoinLink`, `CreditBlindTestSource`
      (contrôles, plafond sous verrou, `RecordPelleMovement`), signeur/vérificateur HMAC dans `Application/Support`.
- [x] Présentation : contrôleurs join, credits (hors firewall JWT, signature obligatoire), réglages admin.
- [x] Config : `BLIND_TEST_APP_URL`, `BLIND_TEST_SHARED_SECRET` (`.env`, `.env.example`, `envs/api.env` en prod).
- [x] Front : bloc de réglages dans l'admin des pelles d'event, bouton « Jouer au blind test » sur la page d'event,
      libellé du motif dans l'historique.
- [x] Tests fonctionnels : signature (bonne, mauvaise, périmée), idempotence, plafond, interrupteur, non-inscrit,
      banni, event terminé, lien de participation ; tests front des réglages et du bouton.

## Dev Notes

- Le plafond se calcule sur les mouvements `blind_test` du joueur pour l'event, lus après `lockMember` : passer le
  contrôle dans le `$inTransaction` de `RecordPelleMovement` ou ajouter au repository une somme par motif.
- Le secret partagé est unique pour l'app ; une rotation demande de redéployer les deux côtés.

## Dev Agent Record

- Bouton « Jouer au blind test » placé sur la page publique de l'event (la page de session d'une inscription ne
  connaît pas l'id de l'event) ; il n'apparaît que si l'API délivre un lien.
- Le plafond est vérifié dans la fermeture `$inTransaction` de `RecordPelleMovement`, après l'écriture et sous le
  verrou du membre : le dépassement annule le mouvement.
- Réglages : nouvelle table `blind_test_setting` (migration `Version20261024110000`), carte dans l'admin des pelles
  de l'event (masquée une fois l'event terminé).
- Fonctionnels (`BlindTestTest`, 12 cas) laissés à la CI : Postgres local éteint. Unitaires, phpstan, cs-fixer,
  ddd, rector et `pnpm gates` verts.
