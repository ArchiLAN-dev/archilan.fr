# Story 43.28: Les moments d'une partie notifiés

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que créateur ou joueur d'une partie perso,
je veux être prévenu quand quelqu'un rejoint, quand les jeux changent, quand la partie démarre et quand elle se
termine,
afin de ne pas avoir à surveiller la page de la partie pour savoir où elle en est.

## Contexte

Demande de Jean (2026-10-11), avec la story 40.6 qui porte ces notifications jusqu'en message privé Discord.

Aujourd'hui, côté partie perso :

- `run_joined` prévient le créateur seulement quand on rejoint **par une annonce** (`JoinOpenRun`, 43.17). Un joueur
  qui arrive par le lien d'invitation ou en acceptant une invitation (`RunJoiner`, `AnswerRunInvitation`) passe
  inaperçu.
- Rien ne prévient quand un joueur choisit ou change ses jeux (`PersonalRunGameSelection`).
- Rien ne prévient les joueurs que la partie a démarré : seuls leurs amis le voient, par l'activité des amis
  (`FriendActivityJob::SESSION_STARTED`).
- `generation_failed` prévient le créateur d'un échec de génération ; rien ne dit aux joueurs que le récap est prêt.

## Critères d'acceptation

1. **Un joueur rejoint** : le créateur est notifié, quel que soit le chemin (annonce, lien, invitation acceptée).
   Le type `run_joined` existant est réutilisé.
2. **Jeux choisis ou changés** : le créateur est notifié qu'un joueur a choisi ou changé ses jeux, avec le nom des
   jeux. Plusieurs changements du même joueur en dix minutes ne donnent qu'une notification (la dernière liste).
   Le créateur qui change ses propres jeux n'est pas notifié.
3. **La partie démarre** : quand la partie passe en cours (serveur prêt), chaque joueur (créateur compris, co-joueurs
   compris) est notifié, avec un lien vers la page de la partie où sont les infos de connexion.
4. **La partie est terminée** : quand le récap est construit, chaque joueur est notifié, avec un lien vers le récap.
5. Chaque nouveau type est réglable dans les préférences de notification (43.11b) : cloche et push par défaut pour
   « la partie démarre », cloche seule pour les autres.
6. Les textes de la cloche et du push sont en français, au ton du site.
7. Gates verts ; tests (les trois chemins pour rejoindre, regroupement des changements de jeux, démarrage et fin à
   tous les joueurs, préférences respectées).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Notifier depuis `RunJoiner`, chemin commun, et retirer l'appel en double de `JoinOpenRun`.
- [x] **Task 2** (AC 2) - Notification de jeux choisis, regroupée sur dix minutes.
- [x] **Task 3** (AC 3, 4) - Notifications de démarrage et de fin, après la transaction.
- [x] **Task 4** (AC 5, 6) - Préférences, textes de cloche et de push (front compris).
- [x] **Task 5** (AC 7) - Tests ; gates.

## Dev Agent Record

- `RunJoiner::join()` dit si le membre vient d'arriver ; `RunJoiner::announce()` prévient le créateur après la
  transaction. Lien (`PersonalRunDrafts::joinByToken`), invitation (`AnswerRunInvitation`) et annonce (`JoinOpenRun`)
  passent tous par là.
- Jeux : `saveMyGames` envoie `NotifyRunGamesChosenJob` avec un délai de 10 minutes quand la liste d'un joueur
  (pas le créateur) change ; le handler se tait si la liste a encore bougé depuis, ou si la partie est lancée.
  Limite connue : une liste qui revient deux fois à la même valeur en 10 minutes peut donner deux notifications.
- Démarrage : `SessionLifecycleManager` envoie `NotifyRunPlayersJob::started` au premier passage en cours de la
  session (même garde que les mails d'événement, `isNotified`), donc une reprise ne renotifie pas. Fin :
  `BuildSessionRecapJobHandler` envoie `recapReady` à la première construction du récap, pas à une reconstruction.
  `NotifyRunPlayersJobHandler` ignore une session d'événement et prévient créateur, participants et co-joueurs.
- Préférences : `run_started` (cloche et push), `run_joined`, `run_games_chosen`, `run_recap_ready` (cloche) ; textes
  de push dans `PushMessageFactory::runMoment` ; cloche et libellés côté front.
- Gates verts : API 2980 tests, front 1034 tests.
