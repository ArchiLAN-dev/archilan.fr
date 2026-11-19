# Story 54.12 - Packs proposés par le catalogue

**Status:** review
**Epic:** 54 - Trackers PopTracker
**Date:** 2026-11-19
**Dépend de:** 54.1 (import d'un pack par lien GitHub), 54.2 (packs par jeu), 31.1 (liens du catalogue).

## Story

En tant qu'administrateur d'ArchiLAN,
je veux qu'on me propose le pack PopTracker qu'indique le catalogue Archipelago pour un jeu,
afin d'équiper les jeux en trackers sans chercher chaque pack à la main.

## Contexte

- Le site synchronise déjà la feuille communautaire des jeux Archipelago (`CatalogSyncService`, cache d'une heure).
  Sa colonne « Links & Downloads » donne des liens nommés : `CatalogEntry::$links`, liste de `{label, url}`.
  `findEntryForNames(catalogSheetName, archipelagoGameName, name)` retrouve la ligne d'un jeu. La story 31.1 s'en
  sert déjà pour les liens du tutoriel (`GameTutorialSeeder`).
- Lecture de la feuille le 2026-11-19 (1 474 lignes) : environ 210 jeux ont un lien libellé `Poptracker`.
  - Formes vues : `github.com/<owner>/<repo>`, `.../releases/latest`, `.../releases/tag/<tag>`, `.../releases`.
    `TrackerPackSource` n'en garde que le propriétaire et le dépôt : l'import les accepte déjà.
  - Certains liens sont enveloppés dans une redirection Google (`https://www.google.com/url?q=<lien>&sa=...`).
  - Quelques-uns ne sont pas importables : un message Discord (`Poptracker (Discord)`), un hôte autre que GitHub
    (`git.ddd.rip`).
  - D'autres libellés ne sont pas des packs : `Tracker (Web)` (OoT), `Encounter Tracker`, `Universal Tracker Maps`.
    `Emotracker` désigne un format proche mais distinct, pas proposé.
  - Un même dépôt peut servir plusieurs jeux (ex. `Br00ty/tloz_brooty` pour The Legend of Zelda et A Link to the
    Past).
- Sans `GOOGLE_API_KEY`, la synchronisation passe par l'export CSV, qui perd les URL : il n'y a alors rien à
  proposer.

## Critères d'acceptation

1. **Pack recommandé dans l'onglet tracker d'un jeu** (admin, `admin-game-tracker-packs`). Quand la ligne du
   catalogue du jeu a un lien `Poptracker` vers un dépôt GitHub qui n'est pas déjà importé pour ce jeu, un encart
   « Pack recommandé par le catalogue » affiche le dépôt (`owner/repo`) et un bouton « Importer ». Le bouton passe
   par l'import existant (54.1) avec l'URL normalisée `https://github.com/<owner>/<repo>`, sans nouvelle règle
   d'import.
2. **Liens retenus.** Seuls les liens dont le libellé commence par « Poptracker » (casse ignorée) sont lus. Une
   redirection Google est déballée (paramètre `q`). Un lien GitHub vers un dépôt est proposé à l'import. Un autre
   lien (Discord, autre hôte) s'affiche en simple lien externe, « à récupérer à la main », sans bouton. Aucun
   autre libellé n'est retenu.
3. **Déjà importé.** Un dépôt est déjà importé si un pack du jeu a la même source GitHub (propriétaire et dépôt,
   casse ignorée). L'encart ne s'affiche pas dans ce cas.
4. **Liste des jeux** (admin) : un panneau repliable « Trackers proposés par le catalogue (N) » liste les jeux
   concernés, chacun avec son dépôt et un bouton « Importer », pour les équiper à la suite sans ouvrir chaque fiche.
   Révisé à l'implémentation : la liste des jeux est paginée et filtrée côté serveur, dans un autre contexte que
   les packs ; un panneau à part évite de mêler les deux et permet d'importer sur place.
5. **Pannes sans effet.** Une feuille indisponible, une clé Google absente ou un jeu introuvable dans le catalogue
   n'affichent rien et ne cassent pas la page : pas d'encart, pas d'erreur. Le calcul de la liste réutilise le
   cache de la feuille : il ne déclenche pas une lecture par jeu.
6. **Rien d'automatique.** Aucun pack n'est importé sans le clic de l'admin. Un pack importé ainsi suit le même
   chemin que les autres : inactif à l'import (54.2), à essayer au banc (54.8) puis à activer à la main.
7. Tests :
   - unitaires : extraction des liens (libellés, redirection Google, formes d'URL GitHub, Discord, autre hôte,
     dépôt partagé par deux jeux) ;
   - fonctionnels : endpoint admin (proposition, déjà importé, catalogue indisponible, accès non admin refusé) ;
   - front : encart et filtre.
   Gates vertes.

## Tâches

- [x] API : extraction des packs proposés depuis `CatalogEntry::$links` (service de domaine ou d'application pur,
      testé seul) (AC 2)
- [x] API : requête admin « packs proposés » pour un jeu et pour la liste des jeux, en excluant les dépôts déjà
      importés (AC 1, 3, 4, 5)
- [x] Front : encart dans l'onglet tracker du jeu, bouton « Importer » sur l'import existant (AC 1, 6)
- [x] Front : panneau de la liste des jeux (AC 4)
- [ ] Tests et gates (AC 7)

## Hors périmètre

- La recherche GitHub des `manifest.json` de packs, pour les jeux absents de la feuille. Elle demanderait un
  `GITHUB_TOKEN` : story à part si le catalogue ne suffit pas.
- Les trackers web (`Tracker (Web)`) et EmoTracker.
- L'import en masse en un clic.

## Notes d'implémentation

- `CatalogPackLink` (Domain) lit un lien du catalogue : libellé, redirection Google, dépôt GitHub via
  `TrackerPackSource::parse`, comparaison de dépôt sans casse.
- `CatalogTrackerPackSuggestions` (Application) : `forGame` (toutes les propositions, importables ou non) et
  `gamesToEquip` (jeux avec au moins une proposition importable). Port `TrackerCatalogInterface`, implémenté par
  `GameSelectionTrackerCatalog` sur `GameCatalogLinksProviderInterface` (cache de la feuille, une heure).
- Routes : `GET /api/v1/admin/games/{gameId}/tracker-pack-suggestions`, `GET /api/v1/admin/tracker-pack-suggestions`.
- Front : `admin-catalog-tracker-packs.tsx` (encart de la fiche, panneau de la liste), import par
  `importTrackerPack`.
- Revue de sécurité (2026-11-19) : la feuille étant modifiable par tous, `fromCatalog` ne garde qu'un lien en
  `https://` (ni `data:`, ni schéma applicatif, y compris après dépliage d'une redirection Google).
