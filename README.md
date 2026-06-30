<div align="center">

# 🎲 archilan.fr

### Le hub communautaire et l'ERP événementiel de **ArchiLAN**

*La branche francophone de la communauté mondiale [Archipelago Multi World Randomizer](https://archipelago.gg/) - association loi 1901 basée à Clermont-Ferrand.*

![Next.js](https://img.shields.io/badge/Next.js_15-000?logo=nextdotjs&logoColor=white)
![TypeScript](https://img.shields.io/badge/TypeScript-3178C6?logo=typescript&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony_7-000?logo=symfony&logoColor=white)
![PHP](https://img.shields.io/badge/PHP_8.3-777BB4?logo=php&logoColor=white)
![Go](https://img.shields.io/badge/Go-00ADD8?logo=go&logoColor=white)
![Python](https://img.shields.io/badge/Python-3776AB?logo=python&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL_17-4169E1?logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-2496ED?logo=docker&logoColor=white)

</div>

---

## ✨ La vision

Archipelago, c'est un randomizer **multi-mondes** : des centaines de jeux (près de 500) reliés dans une même partie, où chaque joueur débloque les objets des autres. C'est génial, c'est technique... et il n'existait **aucune communauté francophone organisée** ni outil pour faire tourner des événements.

**archilan.fr** comble ce vide avec deux casquettes :

- 🌐 **Un hub public** qui rend Archipelago découvrable et accessible au public francophone.
- 🛠️ **Un ERP interne** qui remplace la coordination manuelle (Discord, tableurs) par des outils structurés pour l'équipe bénévole.

> Trois LANs successifs : **14 → 30 → 50 participants** (plafond imposé par la salle, pas par la demande). Affilié Twitch. Ce dépôt, c'est l'infrastructure qui fait tourner tout ça.

---

## 🚀 Ce que la plateforme offre

### Côté joueurs - le hub public

| | Fonctionnalité |
|---|---|
| 🎉 **Événements** | Catalogue public des LANs, pages détaillées, inscription en ligne avec sélection de jeux |
| 🧩 **Configuration de slot** | Éditeur de YAML Archipelago assisté (options par jeu, validation, templates réutilisables) |
| 🕹️ **Parties en un clic** | Lancement d'un serveur Archipelago dédié par session, suivi temps réel de la progression |
| 📅 **Weekly Runs** | Runs hebdomadaires thématiques, classements, leaderboards par catégorie |
| 🎮 **Personal Runs** | Parties privées créées par les membres, à la demande |
| 💡 **Indices & reachability** | Système de hints, sphères d'accessibilité, priorités d'indices |
| 🏆 **Communauté** | Profils joueurs, succès, badges (adhérent, présence live), amis, classements |
| 📰 **Actualités** | News et recaps d'événements |
| 💳 **Adhésion & boutique** | Adhésions en ligne, paiements, espace membre |
| 📺 **Streaming** | Détection de live Twitch, embeds, overlays de session pour les streamers |
| 🔐 **Espace membre** | Compte, activité, inscriptions, parties, amis, confidentialité (RGPD), sécurité |

### Côté équipe - le back-office (ERP)

Un back-office complet pour piloter l'association sans friction :

- **Événements** : création, inscriptions, sélection de jeux, lancement et supervision des sessions (jusqu'au slot individuel).
- **Catalogue de jeux** : synchronisation depuis Google Sheets + enrichissement IGDB, gestion des `.apworld` (import GitHub, suivi des versions), tutoriels d'installation.
- **Weekly Runs** : templates, programmation, suivi.
- **Adhésions & paiements** : suivi, rattachement des paiements.
- **Modération & communauté** : succès, gestion des utilisateurs, modération.
- **Contenu** : actualités, pages d'aide Archipelago.
- **Intégrations** : synchronisation de rôles Discord, configuration des serveurs de session.

---

## 🏗️ Architecture

Monorepo polyglotte, orienté **DDD / CQRS** côté API.

```text
archilan.fr/
├── frontend/        # Next.js 15 (App Router) · TypeScript · Tailwind · TanStack Query
├── api/             # Symfony 7 · PHP 8.3 · DDD + CQRS (18 bounded contexts)
├── bridge/          # Service Python - pont REST temps réel vers les serveurs Archipelago
├── orchestrateur/   # Service Go - cycle de vie des conteneurs de session AP (un serveur/session)
├── traefik/         # Reverse proxy · TLS Let's Encrypt (DNS-01 OVH)
└── _bmad-output/    # Artefacts de planification & d'implémentation (PRD, archi, epics, stories)
```

**Pile d'exécution** : PostgreSQL 17 · Mercure (temps réel SSE) · MinIO (stockage objet) · RabbitMQ (messages asynchrones) · Docker · GitHub Actions → images GHCR.

**Flux d'une partie** : inscription → sélection de jeux + YAML → l'API demande à l'**orchestrateur** de lancer un serveur Archipelago dédié → le **bridge** remonte la progression en temps réel → le **frontend** affiche l'état via Mercure.

### Contextes métier (api/src)

`Identity` · `Events` · `Registrations` · `GameSelection` · `CatalogSync` · `Sessions` · `SessionConfig` · `WeeklyRuns` · `PersonalRuns` · `Community` · `Content` · `Membership` · `Payments` · `Streaming` · `Communications` · `Realtime` · `Legal` · `Shared`

---

## 🧪 Exigence d'ingénierie

Le projet est tenu par des **quality gates non négociables**, exécutées en CI et localement :

| Couche | Gates |
|--------|-------|
| **API** | PHPStan (niveau max) · PHP-CS-Fixer (@Symfony) · PHPUnit (0 notice) · validateur d'architecture DDD |
| **Frontend** | `tsc` strict · ESLint (0 warning) · build Next propre |

Chaque fonctionnalité trace une **story** (méthode BMAD), respecte les frontières DDD (Domaine ← Application ← Infrastructure / Présentation) et n'introduit aucun effet de bord aux frontières. Frontières domaine pures, composants React purs, une unité de travail = une transaction.

---

## ⚡ Démarrage rapide (développement)

**Prérequis** : Docker, Node + pnpm, PHP 8.3 + Composer, Symfony CLI.

```bash
# 1. Services locaux (PostgreSQL, Mercure...)
cp .env.example .env
docker compose up -d

# 2. API (http://localhost:8000)
cd api
cp .env.example .env.local
composer install
symfony server:start --port=8000

# 3. Frontend (http://localhost:3000)
cd frontend
cp .env.example .env.local
pnpm install
pnpm dev
```

Les endpoints applicatifs sont versionnés sous `/api/v1`.

> ⚠️ Ne jamais commiter de secrets réels ni de fichiers d'override locaux (`.env.local`, `.env.prod`).

---

## 📚 Documentation

- 📄 **PRD** : `_bmad-output/planning-artifacts/prd.md`
- 🏛️ **Architecture** : `_bmad-output/planning-artifacts/architecture.md`
- 🎨 **UX / Design** : `_bmad-output/planning-artifacts/ux-design-specification.md`
- 🗺️ **Epics & stories** : `_bmad-output/planning-artifacts/epics.md` · `_bmad-output/implementation-artifacts/`
- 🤖 **Standards agents** : `CLAUDE.md` (racine), `api/CLAUDE.md`, `frontend/AGENTS.md`

---

<div align="center">

**ArchiLAN** - association loi 1901, Clermont-Ferrand · La maison francophone d'Archipelago

[🌐 archilan.fr](https://archilan.fr) · [🎲 Archipelago](https://archipelago.gg)

</div>
