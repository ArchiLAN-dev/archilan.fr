# Processus de release

Depuis la v1.0.0 (2026-11-20), archilan.fr sort à rythme fixe.

## Le calendrier

- **Une release par semaine, le mardi.** Tout ce qui est mergé sur `develop` avant le lundi soir part dedans.
- **Préparation le lundi à 20 h** (heure de Paris), par une routine Claude Code programmée : elle ouvre la PR de
  release vers `main` et ne merge rien.
- **Le mardi**, Jean donne son feu vert sur la PR. Ensuite : merge, tag, release GitHub, retour sur `develop`, vérification
  des images.
- **Gel avant un event** : pas de release dans les 4 jours qui précèdent le début d'une ArchiLAN ou d'une ArchiWAN
  (dates lues sur `https://api.archilan.fr/api/v1/events`, champ `startsAt`). Seuls les hotfix passent.
- **Hotfix** : hors calendrier, circuit habituel (`hotfix/*` depuis `main`, PR vers `main` et `develop`, release de
  patch).
- Le déploiement en production reste manuel, par l'opérateur, à partir de la section « Déploiement » du CHANGELOG.

## Les jalons

Un jalon GitHub par release, nommé « Release du AAAA-MM-JJ » avec sa date d'échéance (le mardi). On y range les
issues et les PR visées. La PR de release est rattachée au jalon de sa semaine ; la routine crée le jalon suivant
s'il manque, pour toujours en avoir quatre d'avance.

## Préparer une release

1. Vérifier les bloqueurs connus :
   - `git log origin/main..origin/develop` : rien à sortir, pas de release ;
   - les exclusions `exp:` de `.trivyignore` : une exclusion expirée bloque la publication de `api-web` ;
   - les derniers runs `docker-publish.yml` sur `develop` : un échec (souvent un CVE publié le jour même) doit être
     corrigé avant ;
   - un event qui commence dans les 4 jours : gel.
2. Choisir la version : **mineure** si une story ou une fonctionnalité est entrée, **patch** s'il n'y a que des
   correctifs. Le numéro vit dans `frontend/package.json` (`api/composer.json` n'en a pas) : ne pas l'oublier.
3. Branche `release/vX.Y.Z` depuis `develop`, avec deux fichiers seulement :
   - `CHANGELOG.md` : nouvelle section `## [X.Y.Z] - AAAA-MM-JJ` en tête, format Keep a Changelog, en français, sans
     tiret long (`—`) ni demi-cadratin (`–`). Une phrase d'intro (migrations Doctrine, variables d'environnement,
     versions des services), puis `### Ajouté`, `### Modifié`, `### Corrigé`, `### Sécurité` selon le contenu, et
     toujours `### Déploiement`. Chaque entrée cite sa story. S'inspirer des sections précédentes ;
   - `frontend/package.json` : champ `version`.
4. PR `release/vX.Y.Z` vers `main`, titre `Release vX.Y.Z`, corps = la section du CHANGELOG.

## Publier (après le feu vert)

1. Merger la PR de release **sans supprimer la branche** (elle sert au retour sur `develop`).
2. Tag annoté `vX.Y.Z` sur le commit de merge de `main`, puis `git push origin vX.Y.Z`.
3. `gh release create vX.Y.Z --title vX.Y.Z --notes-file <section du CHANGELOG>`.
4. PR `release/vX.Y.Z` vers `develop` (retour), merger avec suppression de la branche une fois la CI verte.
5. Attendre les deux runs `docker-publish.yml` (tag et `main`) en succès : images `api-web`, `api-worker`,
   `frontend` en `X.Y.Z` et `X.Y`.
6. Les services (orchestrateur, bridge, archipelago) ne sont tagués que s'ils ont des commits non publiés depuis leur
   dernier tag.
