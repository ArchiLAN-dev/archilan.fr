# Story 54.13 - Durcissement des trackers

**Status:** review
**Epic:** 54 - Trackers PopTracker
**Date:** 2026-11-19
**Dépend de:** 54.1 (import), 54.3 (mises à jour), 54.4 (moteur), 54.6 (autotracking), 54.8 (banc d'essai).

## Story

En tant qu'administrateur d'ArchiLAN,
je veux qu'un pack de tracker, même piégé, ne puisse rien faire d'autre que dessiner son tracker,
afin d'équiper les jeux en packs tiers sans faire courir de risque aux joueurs ni au serveur.

## Contexte

Revue de sécurité du 2026-11-19, sur develop et la branche 54.12. Aucune faille critique ni haute :

- le bac à sable Lua tient (`injectObjects: false`, `enableProxy: false`, `io`, `debug`, `package` et le bytecode
  retirés) ;
- les données d'un pack sont rendues en texte, les images sont typées png, gif, jpeg ou webp ;
- l'import est protégé (SSRF, archive, jeton GitHub) et les routes admin sont gardées.

Restent des points de défense en profondeur, que cette story traite. Le pire scénario retenu : un dépôt de pack
repris ou compromis pousse une nouvelle version piégée, et une évasion du bac à sable (bug de wasmoon) donnerait
au script la main dans un Worker de la même origine que le site.

Choix d'isolement : plutôt qu'une iframe `sandbox` (le Worker d'une origine opaque ne peut charger ni les chunks
Turbopack ni le WASM sans CORS), le script du Worker reçoit sa propre CSP **appliquée**. Turbopack charge le Worker
depuis `/_next/static/chunks/turbopack-worker-*.js` (une vraie URL, pas un blob) : c'est la réponse de ce fichier
qui fixe la CSP du Worker. Le Worker ne peut alors joindre que l'origine du site (le WASM), ni l'API (autre hôte,
seul à porter le cookie de session), ni un serveur Archipelago.

## Critères d'acceptation

1. **Mise à jour sans exposition** : installer une mise à jour (ou une version par lien) d'un pack **activé** ne la
   sert plus aux joueurs. Elle rejoint les versions conservées ; l'admin l'essaie (`?essai=1&version=...`) puis la
   sert avec le bouton existant. Un pack désactivé garde le comportement actuel (servie tout de suite). La réponse
   dit si la version est servie, et l'admin l'affiche.
2. **Adresses de mise à jour** : `versions_url` et le `download_url` du fichier de versions doivent viser le même
   `owner/repo` que la source du pack (insensible à la casse), sinon la vérification les ignore et le journalise.
3. **Archive publique** : un `If-None-Match` qui correspond à l'empreinte rend un 304 sans lire le stockage ; un
   limiteur de débit par IP (large, pensé pour une LAN derrière une seule IP) répond 429 au-delà.
4. **Budget de temps Lua** : un appel au pack (requête ou frame) qui dépasse son budget échoue par une erreur Lua,
   coroutines comprises. Côté page, un Worker qui ne répond plus (frames comprises) est arrêté.
5. **Paquets relayés** : la page ne relaie au serveur Archipelago que des `Get` et `SetNotify` dont les clés sont des
   chaînes, quoi que le Worker envoie.
6. **CSP du Worker** : le script d'amorçage du Worker est servi avec une CSP appliquée
   (`default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'`).
7. **Zip côté navigateur** : un zip qui déclare plus de 200 Mo décompressés ou plus de 5000 fichiers (les plafonds du
   serveur) est refusé avant toute décompression, avec une erreur `archive_invalid` (fflate n'écrit jamais plus que
   la taille déclarée d'une entrée).
8. **Rapports de compatibilité** : chaque message d'erreur est tronqué à 500 caractères avant l'envoi.
9. Gates verts ; tests sur chaque point.

## Tâches

- [x] API : critère 1 (`UpgradeTrackerPack`, réponse, front admin).
- [x] API : critère 2 (`CheckTrackerPackUpdates`, `TrackerPackSource::owns`).
- [x] API : critère 3 (contrôleur d'archive, rate limiter).
- [x] Front : critères 4 à 8.
- [x] Gates.

Hors story : le lien non GitHub du catalogue (54.12) n'accepte plus que `https://`, corrigé dans la PR de 54.12.

## Dev Agent Record

- Critère 1 :
  - `TrackerPack::keepUnserved` garde la version et retire l'offre de mise à jour qu'elle couvre ;
    `UpgradeTrackerPack::offer` sert seulement si le pack est désactivé. `ServedTrackerPackVersion::$served`.
  - `TrackerPackArchiveQuery` : un joueur n'atteint plus que la version servie et les plus anciennes (une page
    rendue avant un changement peut encore en demander une), jamais une version plus récente gardée pour l'essai.
  - Admin : « Essayer » et « Servir cette version » sur chaque version gardée, message quand la version attend.
- Critère 2 : `TrackerPackSource::owns` (github.com, raw.githubusercontent.com, codeload.github.com, api.github.com).
- Critère 3 : `fingerprint()` puis `isNotModified` avant toute lecture du stockage ;
  `TrackerPackArchiveRateLimitListener`, limiteur `tracker_pack_archive` (600 / 10 min par adresse).
  Non fait : l'envoi en flux du zip, qui demanderait de changer `MinioStorageInterface` (Shared) ; le 304 et le
  limiteur bornent déjà la charge.
- Critère 4 :
  - `lua-bootstrap.ts` : échéance `os.clock()` dans le hook (8 s par appel, 1 s par frame handler) ;
    `coroutine.create` et `coroutine.wrap` reposent le hook dans chaque coroutine ; un frame handler qui dépasse
    son budget est retiré. Budgets réglables (`EngineOptions.budgets`) pour les tests.
  - `TrackerClient` : option `heartbeatMs` (5 s sur l'écran), requête `ping` / `pong` soumise au délai de 10 s.
- Critère 5 : `isApOutgoing` dans `isTrackerResponse`.
- Critère 6 : en-tête dans `next.config.ts` sur `/_next/static/chunks/turbopack-worker-*`. Vérifié sur un
  `next start` : l'en-tête est servi, le pack Majora's Mask se charge (108 images), aucune violation de CSP.
- Critère 7 : filtre de `unzipSync` sur la taille déclarée (200 Mo) et le nombre de fichiers (5000).
- Critère 8 : `compatibilityReport` coupe chaque message à 500 caractères.
- Tests : `TrackerPackUpdatesTest` (pack activé, 304, dépôt tiers), `TrackerPackSourceTest`,
  `TrackerPackArchiveRateLimitListenerTest`, `parity.test.ts` (budgets), `tracker-client.test.ts`,
  `worker-host.test.ts`, `pack-files.test.ts`, `compatibility-report.test.ts`, tests admin.
