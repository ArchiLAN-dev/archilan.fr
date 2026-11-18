# Story 33.31: Passage à Symfony 8.1 et mise à jour des dépendances

**Status:** review
**Epic:** 33 - Nettoyage et standards
**Date:** 2026-11-18
**Origine :** six PR Dependabot ouvertes, dont quatre qui montent un seul composant Symfony en 8.1 (#984 à #987).

## Story

En tant que mainteneur,
je veux l'API sur Symfony 8.1 et des dépendances à jour,
afin de rester sur une branche maintenue et de fermer d'un bloc les mises à jour en attente.

## Contexte

- L'API est en Symfony 7.4 (`extra.symfony.require`), PHP déjà en 8.4 minimum (8.5 en prod et en CI).
- Dependabot propose des composants seuls en 8.1 : un mélange 7.4/8.1 n'est pas installable proprement.
- Résolution testée le 2026-11-18 sur une copie : toutes les dépendances externes acceptent Symfony 8.1 ; seuls nos
  paquets `packages/` (`bridge-client`, `bridge-client-bundle`, `orchestrateur-client`) n'autorisent que `^6.4|^7.0`.
- Front : mises à jour mineures seulement (#1117). TypeScript 7 et ESLint 10 restent hors de cette story.

## Critères d'acceptation

1. Les trois paquets de `packages/` autorisent `^8.0` en plus de `^6.4|^7.0` et sont publiés en nouvelle version
   mineure.
2. `api/composer.json` exige Symfony `8.1.*` partout (`require`, `require-dev`, `extra.symfony.require`) ; le lock est
   à jour, toutes les dépendances mineures comprises.
3. Le code et la config n'utilisent plus rien de retiré en Symfony 8 ; `composer gates` est vert, sans dépréciation.
4. Le front intègre les mises à jour mineures de #1117 ; `pnpm gates` est vert.
5. Les PR Dependabot #984 à #987, #1019 et #1117 sont fermées, remplacées par cette story.
6. La release qui porte la story ne contient rien d'autre.

## Tâches

- [x] Paquets : contraintes Symfony, version, tag (AC 1)
- [x] API : contraintes, `composer update -W`, recettes Flex à relire (AC 2)
- [x] API : corrections jusqu'aux gates vertes (AC 3)
- [x] Front : mises à jour mineures (AC 4)
- [ ] PR, fermeture des PR Dependabot, release dédiée (AC 5, 6)

## Notes de dev

- Paquets publiés : `bridge-client` v1.3.0, `bridge-client-bundle` v1.2.0, `orchestrateur-client` v1.19.0
  (`^6.4|^7.0|^8.0`), vérifiés sous Symfony 8.1.11 (PHPStan niveau 9, tests).
- API : Symfony 8.1, Doctrine ORM 3.8, DoctrineBundle 3.4, PHPUnit 13.4, PHPStan 2.3, Rector 2.8 (`composer bump`
  remonte les bornes basses).
- Corrections dues aux nouvelles versions :
  - PHPUnit 13.4 : `TestCase::setStatus()` est `final`, l'utilitaire de `RunBronzeTest` devient `setRunStatus()` ;
  - Messenger 8 : `InMemoryTransport::get()` ne rend plus qu'un message par appel, les tests lisent `getSent()` ;
  - DI 8.1 : l'injection par seul nom de paramètre est dépréciée, `#[Target]` sur les limiteurs et le pool
    `public_reachability.cache` ;
  - DoctrineBundle 3.4 : l'option `alias` des mappings est dépréciée (ORM 3 n'a plus d'alias), retirée ;
  - PHPStan 2.3 voit comme pures des méthodes sans effet visible et retient leur valeur : `@phpstan-impure` sur
    `RunParticipant::recordSlotPreflight()` (écriture par référence), `TwitchStatusChecker::check()` et les lecteurs
    de réponse des tests ; casts et `instanceof` devenus inutiles retirés. L'option globale
    `rememberPossiblyImpureFunctionValues: false` a été essayée puis écartée (elle casse d'autres déductions) ;
  - Rector 2.8 : `??=` et nullsafe appliqués sur 12 fichiers, sans changement de comportement.
- `bin/console debug:container --deprecations` : aucune dépréciation. `lint:container` signale
  `DiscordModerationForum` (défaut `null` de `%env(default::...)%`), déjà présent avant, hors gates.
- Recettes Flex avec mise à jour disponible (doctrine-bundle, framework-bundle, mailer, mercure-bundle,
  monolog-bundle, security-bundle) : non appliquées, la config actuelle démarre sans dépréciation.
- Front : mises à jour mineures (Tailwind, TanStack Query, Tiptap, Lucide, Recharts, msw, fflate). TypeScript 7 et
  ESLint 10 restent à part.
- Gates : `composer gates` vert (4057 tests), `pnpm gates` vert.
