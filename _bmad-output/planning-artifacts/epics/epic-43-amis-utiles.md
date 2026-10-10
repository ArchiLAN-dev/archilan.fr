# Epic 43: Des amis qui servent à jouer

**Statut :** proposé le 2026-10-02
**Date :** 2026-10-02
**Origine :** constat de Jean le 2026-10-02 - la liste d'amis (story 30.7) existe mais ne sert à rien en pratique.

## Constat

La liste d'amis n'a aujourd'hui que des usages **passifs** :

- l'audience `friends` des sections de profil (`AudiencePolicy`, `CommunityProfileView`) ;
- le fil « moi + mes amis » (`CommunityFeedQuery::forViewer`, `community-activity.tsx`) ;
- le filtre « amis » de l'annuaire (`CommunityDirectory`, `friendsOnly`) ;
- les notifications `friend_request_received` / `friend_request_accepted`.

Ajouter un ami ne débloque rien de concret, donc personne n'en ajoute, donc les usages passifs restent vides.
Le cercle vicieux se casse en rendant l'ami **utile pour jouer** (runs, events, hebdo, recaps) et en
**remplissant le graphe sans effort** (suggestions d'après les parties jouées ensemble, ajout par QR en LAN).

## Ce qui existe ailleurs (veille du 2026-10-02)

- **Steam** : amis favoris épinglés en haut, catégories d'amis, *rich presence* (où en est l'ami dans sa
  partie), fil d'activité (jeux, succès), rejoindre la partie d'un ami.
  [Steam chat update](https://steamcommunity.com/updates/chatupdate),
  [guide des fonctions sociales](https://steamnavigator.com/blog/steam-friends-social-features-guide)
- **Discord** : cartes d'activité récentes des amis (temps de jeu, « streaks » de retour sur un jeu), invitation
  d'un ami directement dans un lobby via le Social SDK.
  [Recent Activity FAQ](https://support.discord.com/hc/en-us/articles/22045487931799-Members-List-Recent-Activity-FAQ),
  [PC Gamer - activity log](https://www.pcgamer.com/software/platforms/discord-activity-tracking-log-update/),
  [PC Gamer - Social SDK](https://www.pcgamer.com/gaming-industry/your-discord-friends-list-may-soon-appear-directly-in-the-games-you-play/)
- **Xbox** : liste de favoris (notifications quand ils se connectent / lancent un jeu), *Looking for Group*
  (petite annonce « on cherche des joueurs » à laquelle on postule).
  [Favoris Xbox](https://support.xbox.com/en-US/help/friends-social-activity/friends-groups/add-friend-to-favorites),
  [LFG](https://www.phonearena.com/news/Microsoft-updates-Xbox-beta-app-with-Looking-for-Group-Clubs-features_id85519)
- **Duolingo** : *Friend Quests* (objectif hebdo à deux), *Friend Streaks*, bouton « relancer » un ami ; Duolingo
  annonce +22 % de leçons terminées chez ceux qui ont une friend streak.
  [Blog Duolingo - friend streak](https://blog.duolingo.com/friend-streak/),
  [Blog Duolingo - social features](https://blog.duolingo.com/friends-social-features/)
- **Strava** : kudos, clubs (fil, classement et défis de groupe) ; les sportifs en activité de groupe restent
  plus actifs à 12 mois. [Strava gamification](https://trophy.so/blog/strava-gamification-case-study)
- **Écosystème Archipelago** : les lobbies (ap-lobby, multiworld.gg) rassemblent un groupe autour d'un lien et
  d'un chat avant la génération, sans notion d'amis.
  [Archipelago lobby](https://ap-lobby.ionium.us/), [multiworld.gg lobbies](https://multiworld.gg/lobbies)

À retenir pour ArchiLAN : l'invitation directe (Discord, Steam), la présence riche (Steam), les favoris (Xbox,
Steam), l'annonce « cherche joueurs » (Xbox LFG), l'objectif partagé et la relance (Duolingo), le groupe
d'amis (catégories Steam, clubs Strava). Spécificité à exploiter : le **multiworld** crée des liens que les
autres plateformes n'ont pas (items envoyés, BK débloqués par un autre joueur).

## Principes

- **Un ami = un raccourci**, jamais un passage obligé : tout ce qui marche avec un ami doit rester faisable
  sans (lien d'invitation, annuaire).
- **Le blocage gagne toujours** : un utilisateur bloqué (dans un sens ou l'autre) n'apparaît dans aucune
  suggestion, invitation, présence ni classement entre amis.
- **Anti-spam** : invitations, relances et alertes sont plafonnées et dédoublonnées ; les alertes d'activité
  sont opt-in (favoris).
- **Vie privée** : tout ce qui expose une activité en direct respecte un réglage de visibilité (story 43.6).
- **Réutiliser** : `Notifier` + Web Push (epic 40), présence dérivée (30.14), `SlotCoPlayer`, feed persisté des
  recaps (epic 32), succès en base (30.x), `friendsOnly` de l'annuaire.

## Stories

### Phase 1 - donner une raison d'avoir des amis, et en avoir

- **43.1** - Inviter des amis dans une run perso (notification + push, rejoindre en un clic).
- **43.2** - Suggestions d'amis d'après les parties jouées ensemble.
- **43.3** - Ajout d'ami par lien personnel et QR code (pensé pour la LAN).
- **43.4** - Amis inscrits aux événements.

### Phase 2 - voir ses amis jouer

- **43.5** - Encart « Mes amis en ce moment ».
- **43.6** - Visibilité de la présence (mode discret). *Prérequis de 43.5 et 43.7.*
- **43.7** - Présence riche : où en est l'ami dans sa partie.
- **43.8** - Classements entre amis (classement communautaire et hebdo).
- **43.9** - Historique commun sur le profil d'un ami.
- **43.10** - Les amis dans le recap (items échangés, déblocages).

### Phase 3 - jouer ensemble plus souvent

- **43.11** - Amis favoris et alertes d'activité (découpable en favoris / alertes + préférences).
- **43.12** - Relancer un co-joueur inactif.
- **43.13** - Groupes d'amis.
- **43.14** - Run ouverte aux amis.
- **43.15** - Duel hebdo entre amis.
- **43.16** - Succès sociaux (nouveaux faits pour le moteur de règles existant).
- **43.17** - Annonces « cherche joueurs » (ouverture aux membres).

## Dépendances

- 43.6 avant 43.5, 43.7 et 43.11 (la présence est aujourd'hui visible de tous, anonymes compris).
- 43.1 avant 43.13, 43.14 ; 43.14 avant 43.17.
- 43.2 fournit l'agrégat de co-participations réutilisé par 43.9 et 43.16.
- 43.8 avant 43.15 (le duel s'affiche dans le bloc « Tes amis cette semaine »).
- 43.8 : démarrer après le merge de la story 30.47, qui modifie `LeaderboardQuery`.
- 43.10 change `SlotBlockTracker` (40.1) pour archiver les épisodes de BK au lieu de les supprimer.

## Hors périmètre

- **Import des amis Discord** : le scope OAuth `relationships.read` est réservé aux applications approuvées par
  Discord ; à reconsidérer seulement si l'approbation devient envisageable.
- **Messagerie privée entre membres** : coût de modération élevé (epic 39) pour un gain faible, Discord
  couvre déjà ce besoin.
