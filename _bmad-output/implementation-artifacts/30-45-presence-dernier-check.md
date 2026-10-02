# Story 30.45: Statut « En jeu » basé sur le dernier check

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-10-02

## Story

En tant que membre qui joue à plusieurs jeux en même temps,
je veux que mon statut « En jeu » affiche le jeu sur lequel je joue vraiment, celui qui vient de recevoir un check,
afin de ne plus apparaître sur un jeu fini depuis longtemps simplement parce que la partie tourne encore.

## Contexte

Retour de Jean (2026-10-02) : son profil affiche « En jeu Minecraft Dig » alors qu'il n'a plus rien à y faire depuis
longtemps ; la partie est simplement toujours en cours. Il joue en même temps à Luigi's Mansion et à Sayonara Wild
Hearts, en alternant : le jeu affiché doit suivre le dernier check.

Aujourd'hui (`DbalCommunityPresenceQuery`, story 30.14) : un membre est « en jeu » dès qu'il a un slot dans une session
`running`, et le jeu affiché est **le premier slot trouvé**, sans notion de temps. Les co-joueurs (story 16.17) ne sont
pas comptés.

### Ce qu'on sait d'un check

Le bridge pousse à chaque changement d'état (`players-push`) le `checks_done` de chaque slot ; l'API garde le dernier
envoi (`SessionPlayersSnapshot`, story 17.21). En comparant deux envois successifs, on sait **quand** un slot vient de
faire un check. Rien ne l'enregistre aujourd'hui.

### Contrainte relevée par Jean : un check appartient au slot, pas à la personne

Archipelago ne connaît que le slot. Un slot a un propriétaire et des co-joueurs ; un check ne dit pas lequel d'entre
eux l'a fait. Le signal vaut donc pour **tous les joueurs rattachés au slot**. Pour limiter les faux positifs (Jean
joue Luigi's Mansion, son slot, et est co-joueur du Minecraft Dig de quelqu'un d'autre qui y joue), une règle de
priorité s'applique (AC 3).

## Critères d'acceptation

1. **Dernier check enregistré** : à chaque `players-push`, un slot dont `checks_done` augmente par rapport à l'envoi
   précédent reçoit `last_check_at = maintenant`. Le premier envoi d'une session ne date rien (pas de référence).
2. **Slots candidats** d'un membre : ceux dont il est propriétaire ou co-joueur, dans une session `running`, sans
   goal atteint ni release, et **actifs** : dernier check (ou, sans check, démarrage de la session) il y a moins de
   **30 minutes**.
3. **Jeu affiché** : d'abord les slots dont le membre est **propriétaire**, puis ceux où il est **co-joueur** ; dans
   chaque groupe, le **check le plus récent** l'emporte.
4. Sans slot candidat, le membre n'est pas « en jeu » (même si une de ses parties tourne encore).
5. « En jeu maintenant » de la communauté suit les mêmes règles, du plus récemment actif au moins récent.
6. Forme des réponses inchangée (profil, annuaire, fil, vue d'ensemble, parties privées).
7. `composer gates` passe.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Règle pure « slots dont le nombre de checks a augmenté » ; colonne `session_slot.last_check_at`
      + migration ; `SessionSlot::recordCheckActivity()` ; branchement dans `RecordPlayersSnapshot` ; tests.
- [x] **Task 2** (AC 2-6) - `DbalCommunityPresenceQuery` : propriétaire et co-joueurs (`DbalSlotPlayerSource`),
      fenêtre de 30 minutes, priorité propriétaire puis récence ; tests fonctionnels.
- [x] **Task 3** (AC 7) - Gates.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `SlotCheckActivityTest` (checks en hausse, premier envoi, slot absent, charges mal formées) | écrit avec la règle | `SlotCheckActivity::slotsWithNewChecks` |
| `CommunityPresenceLastCheckTest` (check le plus récent, slot inactif, partie qui démarre, goal atteint, propre slot avant co-joué, datation par `players-push`) | méthode et colonne absentes | `last_check_at`, `recordCheckActivity`, nouvelle requête de présence |

### Notes

- Colonne `session_slot.last_check_at` (migration `Version20261002100000`), datée par `RecordPlayersSnapshot` en comparant
  l'envoi reçu au précédent (règle pure `SlotCheckActivity`) ; une erreur de datation est journalisée et ne coûte jamais
  le snapshot.
- `DbalCommunityPresenceQuery` réécrite sur `DbalSlotPlayerSource` (propriétaire et co-joueurs), avec la fenêtre
  d'activité de 30 minutes (`COALESCE(last_check_at, started_at)`), l'exclusion des slots au goal ou releasés, et le choix
  propriétaire puis récence. « En jeu maintenant » trie par activité la plus récente.
- Deux tests existants créaient une partie démarrée à une date fixe passée ; leur partie démarre désormais quelques
  minutes avant le test, ce qui est le cas qu'ils voulaient vérifier.
- Seuls les checks reçus après le déploiement datent un slot : juste après la mise en prod, les parties déjà en cours
  comptent sur leur date de démarrage, donc ne s'affichent qu'après leur prochain check.

### Gates

- `composer gates` : OK (2562 tests, 15225 assertions).
