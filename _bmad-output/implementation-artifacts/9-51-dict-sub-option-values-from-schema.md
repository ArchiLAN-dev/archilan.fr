# Story 9.51: Valeurs autorisées des sous-options d'un dict, depuis le Schema de l'apworld

**Status:** en cours - couches 1 a 3 ecrites, moitie API bloquee sur le tag v1.8.0 du client
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-08-28
**Stories liées :** [9.33](9-33-authoritative-option-type-table-end-to-end.md) (table de types
autoritaire, qui a fait arriver le type `dict` jusqu'à l'éditeur), [4.17](4-17-literal-dict-options-not-weighted.md)
et [4.20](4-20-nested-dict-option-values.md) (rendu freeform des dicts littéraux).
**Suite :** [9.52](9-52-admin-dict-sub-option-value-override.md), qui couvre les apworlds qui ne
déclarent aucun Schema.

## Story

En tant que joueur qui configure un jeu portant une option de type `OptionDict`,
je veux choisir la valeur de chaque sous-option dans une liste au lieu de la taper à l'aveugle,
afin de ne pas avoir à deviner que `battle_style` accepte `shift` ou `set` et pas `switch`.

## Context

Depuis 9.33, l'éditeur sait qu'une option est un `dict` parce que l'apworld le dit, et non parce que
la forme de sa valeur y ressemble. Le rendu qui en découle (`FreeformDictOption` avec `fixedKeys: true`,
`DictField` dans `yaml-option-editor.tsx`) verrouille les **noms** de sous-clés et laisse leurs
**valeurs** en champ texte libre. Sur `game_options` de Pokemon Platinum, ça donne onze inputs texte où
le joueur doit connaître de tête le vocabulaire de chaque ligne.

### Ce que l'apworld déclare vraiment

Pour un `Choice`, la chaîne existante fait déjà le travail : `describeOption()`
(`RunnerGateway.php:145`) renvoie `{type: "choice", values: [...]}` et l'éditeur rend un `<select>`.
Un `OptionDict` n'a pas d'équivalent : la classe de base d'Archipelago n'impose ni typage des valeurs,
ni énumération. `introspect_options.py` ne peut donc collecter que `default` et `valid_keys`, et
`describeOption()` mappe ce dernier sur `values` - ce sont les **noms de clés**, jamais les valeurs
autorisées.

La seule déclaration machine-lisible qu'Archipelago prévoit pour ça est l'attribut optionnel
`schema = Schema({...})` (bibliothèque `schema`), que les options systèmes valident automatiquement.
Quand un world le pose, il porte exactement l'information qui manque.

### Ce qu'on ne fera pas

L'autre source, plus large, est la **docstring** de la classe d'option : Platinum y liste
`text_speed: mid/slow/fast - Sets the text speed` et ses dix voisines, et cette docstring arrive déjà
côté front (`extractDescription`, `archipelago-yaml.ts:292`). Elle est écartée : le catalogue est écrit
par des dizaines de développeurs indépendants sans convention commune, et une valeur mal extraite qui
**contraint** un select est pire que pas de select du tout. Cette story ne lit que ce qui est déclaré.

### Couverture assumée

Platinum ne déclare **pas** de `Schema` : sa classe `GameOptions(OptionDict)` n'a que `default` et sa
docstring. Cette story ne corrige donc pas le cas qui l'a motivée - c'est le rôle de la 9.52. Elle a
une valeur propre (les worlds qui déclarent un Schema en bénéficient immédiatement, sans saisie
manuelle) et elle pose la moitié aval - transport, persistance, rendu - que la 9.52 réutilise telle
quelle au lieu de la dupliquer.

## Acceptance Criteria

**AC1 - N'inférer que ce qui est déclaré.** Dans la branche `dict` de `introspect_options.py`, si la
classe d'option porte un `Schema`, parcourir `schema.schema` et émettre, par sous-clé, un
`{type, values?}` dérivé des seuls validateurs littéraux : un `Or(...)` de scalaires donne `values`,
un type nu (`str` / `int` / `bool`) donne `type`, un scalaire littéral donne une valeur unique. Tout
validateur non littéral (lambda, `Use`, callable, regex, `Schema` imbriqué) n'émet **rien** pour cette
sous-clé. Aucune lecture de docstring, aucune inférence depuis la forme des défauts.

**AC2 - Transport additif.** Le spec par sous-clé traverse orchestrateur → orchestrateur-client
(`DictTemplateOption`) → `RunnerGateway::describeOption()` sans perte, dans un champ **nouveau**
(ex. `keys`). `values` (les `validKeys`) et `defaults` ne changent ni de nom ni de sens : les
consommateurs actuels ne voient rien.

**AC3 - Persistance sans migration.** Le spec est porté par `Game.optionTypes` (colonne JSON) et
repeuplé par `BackfillGameOptionTypes`, selon le même raisonnement de compatibilité descendante que
9.33 : une ligne écrite avant cette story n'a pas le champ, et les lecteurs traitent son absence comme
« pas de liste connue ». Il est exposé partout où `optionTypes` l'est déjà (`PersonalRunGameSelection`,
`RegistrationGameSelection`, `AdminGameLibrary`, réponse de configure).

**AC4 - Validation à la frontière.** `asOptionTypesMap` est élargi avec des gardes de type et
**écarte** un sous-spec malformé plutôt que de le laisser passer en `mixed`. Un apworld sans Schema
produit exactement la charge utile d'aujourd'hui.

**AC5 - Rendu.** `DictField` rend un `<select>` pour une sous-clé dont on connaît au moins deux
valeurs, et le champ texte actuel sinon. Le select garde toujours une échappatoire de saisie libre
(« Autre… ») : un Schema peut être exact sans être fermé (`default_player_name: custom` attend une
valeur libre, `text_frame` vaut `1-20` **ou** `random`).

**AC6 - Rien ne se perd au chargement.** Si la valeur enregistrée n'est pas dans la liste, elle est
conservée, affichée comme sélectionnée et ajoutée en tête de liste. Jamais de retour silencieux au
défaut.

**AC7 - Aller-retour YAML inchangé.** La sérialisation du dict passe par le même chemin qu'avant, y
compris le guillemetage des scalaires que YAML 1.1 lit comme des booléens (`on`/`off`/`yes`/`no`,
`yaml-11-booleans.ts`). Un dict entièrement piloté par des selects se relit à l'identique.

**AC8 - Gates.** `composer gates` côté api, `pnpm gates` côté frontend, gates propres des dépôts
orchestrateur / archipelago touchés.

## Tasks / Subtasks

- [x] **Task 1** (AC1). `archipelago/introspect_options.py` : extraction du `Schema` dans la branche
      `dict`, avec un walker qui ne reconnaît que les validateurs littéraux et ignore le reste.
      Fixture apworld portant un `OptionDict` avec `Schema` dans sa suite de tests.
- [~] **Task 2** (AC2). Ecrite dans les deux depots ; le tag reste a poser. Orchestrateur + `archilan/orchestrateur-client` : champ `keys` sur
      `DictTemplateOption`, routé par `TemplateOption::fromArray`. Tag du paquet client **avant** que
      l'API ne le lise (voir Dev Notes, ordre de déploiement).
- [ ] **Task 3** (AC2, AC3). API : `describeOption()` reporte `keys` ; `Game::recordOptionTypes` et
      `BackfillGameOptionTypes` le persistent ; il traverse les quatre charges utiles existantes.
      Tests unitaires sur la forme.
- [x] **Task 4** (AC4). Frontend : élargir `OptionSpec` / `asOptionTypesMap` avec gardes de type ;
      jest sur le rejet d'un sous-spec malformé et sur l'absence de régression quand `keys` manque.
- [x] **Task 5** (AC5, AC6, AC7). Frontend : `entryChoices` sur `FreeformDictOption`, select +
      « Autre… » dans `DictField`, conservation de la valeur hors liste. Jest : rendu select,
      valeur inconnue préservée, aller-retour YAML d'un `game_options` complet.
- [ ] **Task 6** (AC8). Gates verts sur les dépôts touchés.

## Dev Notes

- **Le piège du typage inversé.** `RunnerGateway::describeOption()` mappe aujourd'hui `validKeys` sur
  la clé `values`. Un `values` de `dict` ne veut donc **pas** dire la même chose qu'un `values` de
  `choice` : d'un côté des noms de clés, de l'autre des valeurs autorisées. Ne pas réutiliser `values`
  pour le nouveau contenu - d'où le champ `keys` séparé de l'AC2.
- **Ordre de déploiement**, identique à 9.33 : `TemplateOption::fromArray` route tout type inconnu vers
  `TextTemplateOption`. Le client vendored doit modéliser `keys` avant que l'introspection ne l'émette,
  sinon l'information est jetée en silence. Séquence : client tagué → contrainte composer + lock →
  API → images archipelago / orchestrateur.
- **`Schema` peut ne pas être importable.** `introspect_options.py` importe déjà ses classes d'options
  défensivement (`_try_import`) parce que la version d'Archipelago varie. Faire pareil pour `schema` :
  absence de la bibliothèque = aucun `keys` émis, pas une exception.
- **Ne pas toucher aux heuristiques de repli.** Le garde 4.17 (valeur non numérique → dict littéral) et
  le rendu 4.20 (valeurs de sous-clés non scalaires) restent : ils protègent les apworlds non
  réintrospectés.
- **`text_frame` est le contre-exemple à garder en tête** quand on écrit le select : sa plage est
  numérique et il accepte en plus un mot-clé. C'est pourquoi l'AC5 impose l'échappatoire libre plutôt
  qu'une liste fermée.

### Project Structure Notes

- `archipelago/introspect_options.py` (branche `dict`, aujourd'hui `defaults` + `validKeys`).
- Orchestrateur et `api/vendor/archilan/orchestrateur-client` : dépôts séparés.
- `api/src/Sessions/Infrastructure/Http/RunnerGateway.php` (`describeOption`, `fetchOptionTypes`).
- `api/src/GameSelection/Domain/Entity/Game.php` (`optionTypes`, `recordOptionTypes`).
- `api/src/GameSelection/Application/Command/BackfillGameOptionTypes.php`.
- `frontend/src/lib/archipelago-yaml.ts` (`OptionSpec`, `asOptionTypesMap`, `buildOption` branche
  `declared === "dict"`).
- `frontend/src/features/events/yaml-option-editor.tsx` (`DictField`).

### References

- [Source: _bmad-output/implementation-artifacts/9-33-authoritative-option-type-table-end-to-end.md]
- [Source: _bmad-output/implementation-artifacts/4-17-literal-dict-options-not-weighted.md]
- [Source: _bmad-output/implementation-artifacts/4-20-nested-dict-option-values.md]
- [Source: archipelago/introspect_options.py (branche `dict` : `defaults`, `validKeys`)]
- [Source: api/src/Sessions/Infrastructure/Http/RunnerGateway.php:145 (`describeOption`)]
- [Source: frontend/src/features/events/yaml-option-editor.tsx:1115 (`DictField`)]
- [Source: https://github.com/ArchipelagoMW/Archipelago/blob/main/docs/options%20api.md (`OptionDict`,
  `schema`, `valid_keys`)]
- [Source: https://github.com/ljtpetersen/platinum_archipelago/blob/master/options.py (`GameOptions`,
  sans `Schema` : la preuve du besoin de la 9.52)]

## Dev Agent Record

### Ce qui a ete livre

Quatre couches, dans l'ordre ou elles pouvaient partir :

| # | couche | depot | etat |
|---|--------|-------|------|
| 1 | `option_schema.py` + branche `dict` de l'introspection | archipelago | ecrite, 27 tests |
| 2 | `OptionTypeOverride.Keys` + reponse `/apworlds/{hash}/options` | orchestrateur | ecrite, `go test ./...` vert |
| 3 | `DictSubOption` + `DictTemplateOption::$keys`, bump 1.8.0 | orchestrateur-client | ecrite, phpstan + 82 tests verts |
| 4 | `OptionSpec.keys`, `entryChoices`, `DictValueField` | monorepo (frontend) | ecrite, gates verts |
| 5 | `describeOption()` reporte `keys` | monorepo (api) | **bloquee**, voir « Ce qui reste » |

Le cas Platinum est confirme sur pieces plutot que suppose : `GameOptions(OptionDict)` de
[platinum_archipelago](https://github.com/ljtpetersen/platinum_archipelago/blob/master/options.py)
ne declare ni `schema` ni `valid_keys`. La story ne le corrige donc pas, comme annonce - c'est
la 9.52 qui s'en charge.

### Ecarts assumes

#### `values` seul sur le fil, pas `{type, values}`

L'AC1 prevoyait d'emettre aussi un `type` par sous-cle (`str` -> texte, `int` -> nombre,
`bool` -> booleen). A l'ecriture, aucun de ces types ne sert :

- un `int` n'a pas de vocabulaire, donc pas de liste a proposer ;
- un `bool` ne doit surtout pas devenir un select. Ses valeurs partiraient en `"true"`/`"false"`,
  et le serialiseur guillemette les scalaires que YAML 1.1 lit comme des booleens - c'est
  exactement le chemin par lequel un booleen devient une chaine a la generation.

Restait un champ mort a porter dans quatre depots. Il n'est pas emis. `literal_value()` refuse
d'ailleurs explicitement les booleens, pour la meme raison.

#### La valeur hors liste va dans le champ libre, pas en tete de liste

L'AC6 demandait qu'une valeur inconnue soit « ajoutee en tete de liste ». Elle est plutot
conservee dans le champ libre, avec « Autre… » selectionne. L'injecter dans la liste la ferait
passer pour declaree par l'apworld, alors qu'elle vient du YAML du joueur : les deux ne se valent
pas, et c'est precisement la distinction que le reste de la story defend.

Le resultat visible est le meme - rien n'est perdu, rien n'est ramene a un defaut.

#### L'extracteur vit dans son propre module

`introspect_options.py` fait tout son travail a l'import : il lit `argv`, charge les sources
d'Archipelago, importe les apworlds. Rien de ce qu'il contient n'est testable seul. Les fonctions
pures sont donc dans `option_schema.py`, ce qui donne 27 tests unitaires la ou la story n'avait
prevu qu'une fixture d'apworld.

### Le refus est la fonctionnalite

L'essentiel du code, et la quasi-totalite des tests, portent sur ce que l'extracteur **n'emet
pas**. Un `Or("random", str)` ne donne rien, alors qu'il serait tentant d'en tirer `["random"]` :
la branche `str` accepte tout le reste, et la liste partielle aurait l'air autoritaire tout en
cachant ce qui manque. Meme traitement pour un lambda, un `Use`, un type nu, un schema imbrique,
une cle non litterale - et pour une liste qui, apres filtrage, tombe a une seule valeur.

Une demi-liste dans un select est pire que pas de liste : le joueur ne voit pas ce qui manque, et
n'a aucun moyen de le taper.

### Une decouverte au passage

`describeOption()` mappait deja `validKeys` sur une cle nommee `values`. Sur une option `dict`,
`values` porte donc les **noms** des sous-reglages, la ou sur un `choice` il porte les **valeurs**.
Reutiliser ce champ aurait propose des noms de cles dans un select comme si c'etaient des valeurs.
D'ou le champ `keys`, distinct, et le commentaire qui dit la difference dans les quatre depots.

## Ce qui reste

**Le tag `v1.8.0` de `archilan/orchestrateur-client`.**

`TemplateOption::fromArray` route tout ce qu'il ne modelise pas vers `TextTemplateOption` : tant
que le paquet ne porte pas `DictSubOption`, l'API ne peut pas lire `keys`, et `composer gates`
echouerait sur une propriete inexistante. La branche `feature/dict-sub-option-values` est ecrite
et verte dans le depot du client ; il manque la publication.

Une fois le tag pose :

1. `composer require archilan/orchestrateur-client:>=1.8.0` dans `api/`
2. `describeOption()` reporte `keys` (AC2, moitie monorepo)
3. `Game.optionTypes` le persiste, `BackfillGameOptionTypes` le repeuple (AC3)
4. re-introspection du catalogue pour que les jeux existants remontent leurs vocabulaires

Ordre de **deploiement** ensuite, identique a 9.33 : l'API avant les images archipelago et
orchestrateur, sinon `keys` est jete en silence faute d'etre modelise cote client.

### Change Log

| Date       | Change |
|------------|--------|
| 2026-08-28 | Couches 1 a 4 ecrites (introspection, orchestrateur, client, frontend). Ecarts : `values` seul sur le fil, valeur hors liste au champ libre, extracteur sorti dans `option_schema.py`. Couche API differee, en attente du tag v1.8.0 du client. |
| 2026-08-28 | Creee. Extraire les valeurs autorisees des sous-options de dict depuis le `Schema` declare par l'apworld, jusqu'au select dans l'editeur. Parsing de docstring explicitement ecarte. Status: draft. |
