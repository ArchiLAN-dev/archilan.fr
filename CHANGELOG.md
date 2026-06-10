# Changelog

Toutes les versions notables d'archilan.fr sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet adopte le [versionnage sémantique](https://semver.org/lang/fr/).

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

[0.1.0]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.1.0
