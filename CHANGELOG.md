# Changelog

Toutes les versions notables d'archilan.fr sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet adopte le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.4.1] - 2026-06-15

Correctif de production sur la connectivité API → bridge des sessions.

### Corrigé

- **Runs hebdomadaires & parties en production** : l'API ne parvenait pas à joindre le
  bridge d'une session lancée (erreur 503 « bridge non disponible » sur la page « ma run »,
  et liste de patchs vide). En production l'API tourne dans un conteneur et ne pouvait pas
  atteindre le port publié du bridge via l'IP publique. Les appels API → bridge passent
  désormais par un host configurable (`BRIDGE_HTTP_HOST`).

### Déploiement

- Nouvelle variable d'environnement **`BRIDGE_HTTP_HOST`** (prod : `host.docker.internal`,
  dev : `localhost`) et ajout de `extra_hosts: ["host.docker.internal:host-gateway"]` sur
  `api-web` / `api-worker` dans `docker-compose.prod.yml`.

## [0.4.0] - 2026-06-15

Itération centrée sur le couplage de la bibliothèque Steam et la refonte de la page Jeux.

### Ajouté

- **Couplage bibliothèque Steam** : sur la page Jeux et sur la sélection de jeux d'une
  partie, l'utilisateur renseigne son compte Steam (URL de profil, pseudo ou SteamID64) pour
  voir, dans le catalogue, les jeux qu'il possède et qui sont jouables à ArchiLAN
  (étiquette « Tu possèdes ce jeu »). Profil privé géré avec un message clair.
- **Compte Steam enregistrable** : les membres connectés peuvent enregistrer leur compte
  Steam (dans l'espace compte et depuis la page Jeux) ; le couplage est alors automatique
  aux visites suivantes.
- **Refonte de la page Jeux** : catalogue chargé côté client avec **recherche instantanée**,
  filtres (disponibilité, « Mes jeux »), tri, et couplage Steam intégré à la grille.
- **Catégories de plateformes** : filtres par familles (Super Nintendo, GameCube, Nintendo 64,
  PC, PlayStation, Switch, Mobile…) déduites d'IGDB, plus une facette « Steam », sur la page
  Jeux et la sélection de jeux d'une partie.

### Technique

- Catalogue enrichi du `steamAppId` et des plateformes IGDB (commandes de backfill
  `app:games:backfill-steam-app-ids` et `app:games:backfill-platforms`).
- Client Steam Web API + endpoint public de couplage ; endpoint catalogue complet
  `GET /games?all=1`.
- Nouvelle variable d'environnement **`STEAM_WEB_API_KEY`** ; migrations
  `game_catalog_sync.steam_app_id`, `game_catalog_sync.platforms`, `user.steam_profile`.

## [0.3.0] - 2026-06-14

Itération centrée sur les indices Archipelago, le cycle de vie des sessions de runs
hebdomadaires et la fiabilité du suivi temps réel.

### Ajouté

- **Indices payants par le propriétaire** : un joueur peut acheter un indice (item ou
  location) avec ses propres points sur sa run hebdomadaire et sur sa partie personnelle ;
  les boutons d'indice « gratuit (admin) » restent réservés aux admins.
- **Indices live sur tous les slots** + commande admin d'indice par item, via la
  data-storage Archipelago.
- **Cycle de vie des sessions de runs hebdomadaires** : la partie hebdo suit désormais le
  même mécanisme que les parties privées - détection d'arrêt du conteneur (idle/stoppé) et
  bouton « Relancer ma partie » ; les pages se rafraîchissent automatiquement (poll adaptatif).
- **Bornes de range introspectées** dans l'éditeur YAML, et validation à la sauvegarde :
  blocage des poids tous à 0 et des valeurs hors `[min, max]` (modes simple, avancé et
  template admin).
- **Runs rejointes** visibles dans « Mes parties ».
- **Réglages de monde « host-gated »** appliqués à la génération.

### Modifié

- **Temps du leaderboard hebdo** compté depuis le **lancement de la partie** du joueur
  (et non depuis la génération du run).
- **Coût d'indice** affiché désormais **autoritaire** (lu depuis Archipelago), corrigeant
  les valeurs gonflées.
- Résilience renforcée de la session et du temps réel sur les pages passives.
- Révocation des refresh-tokens par famille avec fenêtre de grâce anti-rejeu.

### Corrigé

- Un crash de génération/lancement bascule la session en état **échec terminal visible** ;
  une session crashée/bloquée reste **relançable**.
- Chargement des mondes Archipelago lors du calcul de réatteignabilité (worlds Universal
  Tracker comme `pokepark`, et `pokemon_emerald` via `pkg_resources`).

## [0.2.1] - 2026-06-11

Correctif d'un blocage de génération sur les parties privées, plus deux améliorations
autour des fichiers générés (servis depuis le stockage durable MinIO).

### Corrigé

- **Génération des parties privées en template par défaut** : un BOM UTF-8 en tête des
  templates apworld faisait échouer la lecture du YAML (jeu vidé), donc la génération
  échouait et le serveur ne démarrait jamais. Le BOM est désormais retiré (au parse et à
  l'ingestion), les templates existants sont nettoyés, et un échec de configuration n'immobilise
  plus la partie sur « démarrage ».

### Ajouté

- **Téléchargement du spoiler** d'une partie privée par son propriétaire (ou un admin),
  servi depuis le stockage durable - disponible quel que soit l'état de la partie.
- **Patchs des joueurs** d'une partie privée servis depuis le stockage durable : chaque
  joueur récupère le patch de son slot même partie arrêtée/en veille.

## [0.2.0] - 2026-06-11

Deuxième release : refonte du cycle de vie des runs (restart/idle), configuration
de session et overrides, runs hebdomadaires publiques, et une large passe
responsive/UX sur tout le site et l'administration.

### Ajouté

- **Configuration de session (épic 27)** : modèle de configuration côté domaine,
  persistance, overrides par périmètre (hebdo / privé / événement), formulaire
  d'administration, infobulles d'aide, et verrouillage de l'auto-shutdown pour les
  runs privées.
- **Runs hebdomadaires publiques** : navigation publique des runs de la semaine
  et entrée de menu dédiée.
- **Téléchargement des patchs** d'une run perso côté membre.
- **Scheduler de nettoyage (épic 13)** : purge planifiée des données temporaires
  et des logs avec rétention configurable.
- **Bibliothèque de jeux (admin)** : comptage réel des utilisations d'un jeu et
  tri par nom ou par nombre d'utilisations.
- Tri et affichage responsive de la gestion des événements (admin).
- Vitrine des fonctionnalités et slogan sur la page d'accueil.

### Modifié

- **Refonte restart / idle (épic 17)** : suppression du wake-on-connect ; l'idle
  est désormais géré nativement par l'`auto_shutdown` d'Archipelago, et la relance
  se fait manuellement depuis une sauvegarde.
- Rafraîchissement temps réel plus réactif (`staleTime` 5 s → 2 s).
- Slot « Bridge » masqué de l'interface.
- Large passe responsive sur les tableaux et pages d'administration ainsi que sur
  les pages publiques (accueil, runs, navigation mobile/tablette).
- Dependabot cible désormais `develop` plutôt que `main` (respect du Gitflow).

### Corrigé

- Relance d'une session restée à l'état « stopped ».
- Message contradictoire du panneau « idle ».
- Débordements d'affichage : détails de connexion, infobulles, noms de patchs,
  descriptions d'événements, menu mobile, navigation tablette, progression des slots.
- Expiration des images d'événement.
- Formulations diverses (vocabulaire « seed »).

## [0.1.0] - 2026-06-09

Première version publiée d'archilan.fr - le site et l'ERP de l'association
ArchiLAN autour d'Archipelago (multiworld). Cette release inaugure le tag de
version et la publication d'images Docker versionnées sur GHCR.

### Plateforme

- **Site public** : page d'accueil, présentation d'Archipelago, événements,
  catalogue de jeux, actualités, intégration du live Twitch, pages légales
  (mentions, confidentialité, CGU, CGV).
- **Comptes & adhésions** : inscription, connexion, confirmation d'e-mail,
  réinitialisation de mot de passe, espace membre, adhésions et paiements.
- **Événements** : création/édition côté admin, cycle de vie
  (brouillon → publié → en cours → terminé), inscriptions, capacité,
  visibilité publique/privée (accès protégé par mot de passe), sélection de
  jeux par participant, récaps (VOD + article).
- **Runs Archipelago** : sessions de jeu pilotées via l'orchestrateur et le
  bridge, progression des joueurs en temps réel (Mercure/SSE), résultats.
- **Runs privées** : salons privés gérés par leur propriétaire, invitations.
- **Runs hebdomadaires** : templates par jeu, génération de la run de la
  semaine, page « ma run » côté membre, historique.
- **Administration** : tableau de bord, gestion des utilisateurs, du
  catalogue, des actualités, du bot Discord et de la configuration des
  sessions.

### Ajouté

- **Configuration des sessions configurable (epic 27)** : profils de
  configuration serveur & génération par type de session (hebdo / événement /
  privée), avec surcharge par périmètre :
  - hebdo = par template (admin uniquement),
  - événement = par session (admin),
  - privée = par run (propriétaire).
  - Résolution profil ⊕ override champ par champ, propagée jusqu'au serveur
    Archipelago (release/collect, remaining, countdown, anti-triche, coût des
    indices, points par check, compatibilité, arrêt auto, mot de passe,
    plando, mode course, niveau de spoiler).
  - Mot de passe de connexion défini uniquement en override, avec proposition
    aléatoire par défaut.
  - Test E2E hebdomadaire prouvant qu'une option configurée atteint bien le
    serveur lancé.
- **Runs hebdomadaires** : bouton « Générer la run de la semaine » par
  template ; tableau « Items non reçus » sur l'onglet objets côté membre.
- **Accueil dynamique** : la section « Nos événements » affiche désormais les
  vrais événements (à venir et passés) au lieu d'un contenu statique.

### Modifié

- Refonte de la page de configuration des sessions (sections, interrupteurs,
  alignement, mise en page deux colonnes) et du panneau « Configuration
  avancée (override) » : sections cohérentes, valeurs héritées du profil
  affichées, en-tête avec icône.
- Uniformisation des boutons d'action de la page admin des événements.
- Formulation française clarifiée sur les écrans de configuration.

### Corrigé

- **Authentification multi-onglets (story 13.4)** : coordination du
  rafraîchissement proactif des tokens entre onglets pour éviter les
  déconnexions lors de l'ouverture quasi simultanée de plusieurs onglets.
- Attente de la résolution de l'authentification avant redirection sur les
  pages de run hebdomadaire (plus de redirection prématurée au chargement à
  froid).

### CI / Infrastructure

- Publication d'images Docker sur GHCR pour `api-web`, `api-worker` et
  `frontend`, désormais **taguées par version** (`0.1.0`, `0.1`) lors d'un tag
  git `v*.*.*`, en plus de `latest` (sur `main`) et `sha-<court>`.
- Pipelines backend (PHPStan, PHP-CS-Fixer, PHPUnit, validation
  d'architecture DDD) et frontend (typecheck, lint, build) sur chaque PR.

[0.2.1]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.2.1
[0.2.0]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.2.0
[0.1.0]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.1.0
