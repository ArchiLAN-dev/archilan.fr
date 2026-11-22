# Processus de release

Depuis la v1.0.0 (2026-11-20), archilan.fr sort à rythme fixe.

## Le calendrier

- **Une release par semaine, le mardi.** Tout ce qui est mergé sur `develop` avant le lundi soir part dedans.
- **Préparation le lundi à 20 h** (heure de Paris), par une routine Claude Code programmée : elle ouvre la PR de
  release vers `main` et ne merge rien.
- **Le mardi**, Jean donne son feu vert en mergeant la PR. Le workflow `release.yml` fait le reste : tag, release
  GitHub, images, retour sur `develop` (voir « Publier »).
- **Gel avant un event** : pas de release dans les 4 jours qui précèdent le début d'une ArchiLAN ou d'une ArchiWAN
  (dates lues sur `https://api.archilan.fr/api/v1/events`, champ `startsAt`). Seuls les hotfix passent.
- **Hotfix** : hors calendrier, `hotfix/*` depuis `main` avec la version de patch et sa section du CHANGELOG, PR vers
  `main`. Son merge publie la release comme pour une release du mardi.
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

## Publier : le merge suffit

Le merge d'une PR `release/*` ou `hotfix/*` dans `main` lance `.github/workflows/release.yml` (story 33.33). Une PR
fermée sans merge, ou venant d'une autre branche, ne lance rien. Le workflow :

1. lit la version dans `frontend/package.json` du commit de merge et sa section `## [X.Y.Z]` dans `CHANGELOG.md`.
   Il s'arrête avant de rien publier si le tag `vX.Y.Z` existe déjà ou si la section manque ;
2. pose le tag annoté `vX.Y.Z` sur le commit de merge et crée la release GitHub avec la section du CHANGELOG ;
3. ouvre la PR de la branche vers `develop` (ou reprend celle qui existe déjà) et la merge en supprimant la branche.
   Ses contrôles Backend et Frontend sont ceux du commit déjà testé sur la PR vers `main` ;
4. lance `docker-publish.yml` sur le tag et attend son résultat : images `api-web`, `api-worker`, `frontend` en
   `X.Y.Z` et `X.Y`. Un tag poussé par le workflow ne déclenche pas la publication tout seul (limite du
   `GITHUB_TOKEN`), d'où ce lancement explicite. Le push sur `main` publie `latest` à part, comme avant ;
5. écrit un résumé dans le run : version, liens de la release et de la PR de retour, état des images.

Ne pas supprimer la branche au merge de la PR : le retour sur `develop` en a besoin, et le workflow la supprime après.

### Si le workflow échoue

- **Version ou section manquante, tag existant** : rien n'est publié. Corriger par une PR `hotfix/*` (ou republier à
  la main avec les commandes ci-dessous).
- **Retour sur `develop` en conflit ou non mergé** : le run passe quand même, avec un avertissement ; la PR reste
  ouverte. Résoudre le conflit, merger, supprimer la branche.
- **Images en échec** (souvent un CVE publié le jour même, cf. Trivy) : le run échoue, GitHub notifie. Corriger, puis
  relancer les jobs en échec du run `docker-publish.yml` du tag. Ne pas relancer `release.yml` : le tag existe déjà.

À la main, en dernier recours (les mêmes étapes) :

```
git tag -a vX.Y.Z <commit de merge> -m vX.Y.Z && git push origin vX.Y.Z
gh release create vX.Y.Z --title vX.Y.Z --notes-file <section du CHANGELOG> --verify-tag
gh workflow run docker-publish.yml --ref vX.Y.Z
gh pr create --base develop --head <branche> && gh pr merge <n> --merge --delete-branch
```

Les services (orchestrateur, bridge, archipelago) ne sont tagués que s'ils ont des commits non publiés depuis leur
dernier tag, à la main.
