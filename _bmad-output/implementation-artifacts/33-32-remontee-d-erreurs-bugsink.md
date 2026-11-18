# Story 33.32: Remontée d'erreurs avec Bugsink

**Status:** review
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-18
**Origine :** incident du 2026-11-18 (v0.70.2) : des identifiants Archipelago au-delà de 2^31 faisaient échouer chaque
push du fil, et l'erreur n'a été vue que parce que Jean a collé les logs de prod.

## Story

En tant qu'administrateur d'ArchiLAN,
je veux que les erreurs de l'API, du worker, du front et du bridge remontent groupées dans un outil, avec une alerte,
afin de voir un incident de prod sans lire les logs des conteneurs.

## Contexte

- Aujourd'hui, les erreurs de l'API ne sont que dans les logs JSON (`monolog`, `when@prod` : `fingers_crossed` vers
  stderr et `var/log/prod.log`). Le front et le bridge n'ont rien de centralisé.
- Choix fait avec Jean le 2026-11-18 : pas de Sentry (l'auto-hébergé est trop lourd pour nos serveurs, l'offre
  gratuite part trop vite en quota). **Bugsink** : un seul conteneur, léger, auto-hébergé, compatible avec les SDK
  Sentry. Changer d'outil plus tard (GlitchTip, Sentry) ne demande que de changer le DSN.
- Les données restent chez nous (RGPD) ; les traces peuvent tout de même contenir des pseudos ou des e-mails, d'où le
  filtrage ci-dessous.

## Critères d'acceptation

1. **Conteneur Bugsink** dans `docker-compose.prod.yml`, derrière Traefik sur son propre domaine (variable
   `BUGSINK_DOMAIN`), avec un volume pour ses données, des limites mémoire et CPU explicites, et un exemple
   `envs/bugsink.env.example`. Accès à l'interface réservé (compte admin créé au premier démarrage, inscription
   fermée).
2. **Rétention bornée** : nombre d'événements conservés par problème et durée de conservation réglés, pour qu'une
   erreur en boucle ne remplisse pas le disque.
3. **API et worker** : `sentry/sentry-symfony`, activé seulement si `SENTRY_DSN` est défini (vide = rien n'est envoyé,
   comme en dev et en test). Les exceptions non gérées et les logs `error`/`critical` de Monolog remontent, avec
   l'environnement et la version (`ARCHILAN_VERSION`). Pas de traces de performance.
4. **Front** : `@sentry/nextjs`, côté serveur et navigateur, activé seulement si le DSN public est défini (via
   `src/lib/env.ts`). Erreurs seulement : pas de replay, pas de performance. Erreurs bénignes connues ignorées
   (coupures réseau, extensions de navigateur, `ResizeObserver loop`).
5. **Bridge** : `sentry-sdk` Python, activé par `SENTRY_DSN` passé par l'orchestrateur au lancement d'un bridge, avec
   l'identifiant de session en tag. (Dépôt séparé : PR dans `ArchiLAN-dev/Archipelago-Bridge`, et passage de la
   variable dans l'orchestrateur.)
6. **Données personnelles** : `send_default_pii` désactivé partout ; en-têtes `Cookie`, `Authorization`,
   `X-Internal-Secret` et corps de requête jamais envoyés ; e-mails masqués dans les messages.
7. **Alerte** : un mail à l'équipe au premier événement d'un nouveau problème (pas à chaque occurrence).
8. Gates vertes ; un test vérifie qu'aucun envoi n'a lieu sans DSN. Procédure de mise en service décrite dans `docs/`
   (création du compte, des projets, récupération des DSN, variables à poser).

## Tâches

- [x] Conteneur Bugsink, Traefik, volume, limites, rétention (AC 1, 2)
- [x] API et worker : SDK, Monolog, filtrage (AC 3, 6)
- [x] Front : SDK Next.js, filtrage, erreurs ignorées (AC 4, 6)
- [x] Bridge et orchestrateur : SDK Python, variable passée au lancement (AC 5, 6)
- [x] Alerte mail (AC 7)
- [x] Doc de mise en service, tests, gates (AC 8)

## Notes

- À vérifier à l'implémentation : version exacte de l'image Bugsink, ses besoins mémoire réels et ses options de
  rétention (documentation officielle), plutôt que des valeurs supposées.
- L'erreur de prod du 2026-11-18 est le cas de test grandeur nature : un push du fil en échec doit apparaître comme un
  seul problème avec son compteur.

## Notes de dev

- Bugsink `bugsink/bugsink:2.6.3`, base `bugsink` dans notre Postgres (leur exemple officiel est en Postgres), réseaux
  `default`, `traefik` et `archilan-callbacks` (les bridges n'ont pas Internet). 768 Mo, 1 CPU ; ~315 Mo au repos.
  `PHONEHOME=False` (actif par défaut, vérifié).
- API : `sentry/sentry-symfony` 5.16 (recette contrib ignorée : bundle et config à la main). `register_error_listener`
  coupé, Messenger aussi : Symfony journalise déjà une exception non gérée et un message en échec, et les handlers
  Monolog `ExceptionToSentryIssueHandler` / `LogToSentryIssueHandler` (niveau error, prod) les remontent ; sinon
  doublon. L'ancien `Sentry\Monolog\Handler` est déprécié. `SentryEventScrubber` : 4xx écartées, requête réduite
  à méthode et chemin, e-mails masqués (message, exceptions, extras).
- Front : `@sentry/nextjs` 11, `instrumentation.ts` (serveur, DSN lu au runtime) et `instrumentation-client.ts`
  (navigateur, DSN au build par la variable GitHub `NEXT_PUBLIC_SENTRY_DSN`, version = `steps.meta.outputs.version`).
  Pas de `withSentryConfig` : pas d'envoi des source maps, les traces navigateur restent minifiées (à reprendre si
  besoin, avec un jeton d'upload).
- Bridge (`ArchiLAN-dev/Archipelago-Bridge`) : `core/error_reporting.py`, `session_id` en tag. Orchestrateur :
  `BRIDGE_SENTRY_DSN` passé en `SENTRY_DSN` avec le tag de l'image en `SENTRY_RELEASE`.
- Vérifié de bout en bout contre un Bugsink local : exception d'une commande prod reçue une fois, avec version,
  environnement et e-mail masqué ; 404 ignorée ; erreur du bridge reçue avec son `session_id`.
- Mise en service : `docs/remontee-erreurs.md`.
