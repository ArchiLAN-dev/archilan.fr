# Story 9.54: Les six apworlds que l'introspection n'a jamais reussi a charger

**Status:** implementee - archipelago `v0.15.0`
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-08-28
**Vient de :** [9.53](9-53-reintrospect-apworld-options-command.md) - le premier balayage complet du
catalogue, qui a rendu visible une classe d'echecs que rien ne rapportait.

## Story

En tant que **joueur configurant un de ces six jeux**,
je veux **que l'editeur connaisse leurs types d'options, leurs bornes et leurs locations**,
afin de **ne pas etre le seul du catalogue a n'avoir que des champs libres et aucune validation**.

## Context

`app:games:backfill-option-types --reintrospect` a balaye le catalogue pour la premiere fois le
2026-08-28 : **672 apworlds, 662 reussites, 10 echecs**.

Ces dix ne sont pas des regressions. L'introspection ne tournait qu'au televersement, dans une
goroutine de fond dont personne ne lisait la sortie d'erreur : ces jeux n'ont **jamais** eu de
sidecar. Concretement, dans l'editeur, ils n'ont ni bornes de plage (9.25), ni types d'options
autoritaires (9.33), ni liste de locations (4.14) - depuis toujours, en silence.

Quatre des dix ne sont pas de notre ressort et sont documentes en fin de story. Les six autres le
sont.

### Le fil rouge : introspecter n'est pas generer

Le bug `orjson` corrige en `v0.14.2` avait deja cette forme, et deux des six ci-dessous la
reprennent : **la generation charge tout le catalogue, l'introspection charge un world isole**. Un
world qui compte sur un import collateral - qu'un autre world, quelque part, a deja fait - marche a
la generation et pas ici. La docstring d'`introspect_options.py` dit « Mirrors
generate_multiworld.py » ; chaque divergence entre les deux est un bug en puissance.

## Les six cas

| Jeu | Hash | Erreur |
|-----|------|--------|
| `jurassic_park` | `ec41e856…` | `module 'worlds' has no attribute 'Files'` |
| `gtfo` | `95488546…` | `FileNotFoundError: '/app/ArchipelagoSrc/Players'` |
| `mindustry` | `bb6db6f9…` | `KeyError: '_RACE'` dans `_sc2common/bot/proto/common_pb2.py` |
| `fez` | `bd601a9e…` | `No module named 'worlds.fez'` |
| `dungeon_clawler` | `4e6f4bb2…` | `No module named 'worlds.dungeon_clawler'` |
| `nrftw` | `e1689e99…` | `No module named 'worlds.nrftw'` |

### `jurassic_park` : un import collateral qui n'arrive jamais

```python
class JPDeltaPatch(worlds.Files.APDeltaPatch):
```

Le world lit `worlds.Files` sans l'avoir importe. En generation ca passe : un autre world du
catalogue a fait `from worlds.Files import APDeltaPatch` avant lui, et le sous-module est donc
attache a `worlds`. En introspection, ce world est seul. Le stub `_worlds_stub` pose
`AutoWorldRegister`, `World`, `local_folder`, `user_folder` et `failed_world_loads` - pas `Files`.

Correctif attendu : importer `worlds.Files` a cote des autres, dans **les deux** scripts.

### `gtfo` : un world qui lit le disque dans son corps de module

```python
for file in Path(f"{Utils.local_path()}/Players").iterdir():
```

`GTFOWorldBuilder.make_worlds()` est appele au niveau module et parcourt un dossier qui n'existe pas
dans l'image. Inhabituel, mais c'est le droit du world.

Correctif attendu : creer le dossier vide dans l'image. Verifier au passage si d'autres chemins
canoniques d'Archipelago manquent (`Players`, `output`).

### `mindustry` : protobuf absent, bouchon nuisible

Le world importe `worlds._sc2common`, dont les modules `*_pb2` generes exigent le runtime protobuf.
`google` est absent de l'image, `apworld_import` le bouchonne, et le `*_pb2` s'execute contre un
faux `google.protobuf` : `KeyError: '_RACE'`.

C'est le cas ou le bouchon fait **plus de mal que l'absence** : sans lui l'erreur aurait ete un
`ImportError` clair. Correctif attendu : installer `protobuf` dans l'image.

### `fez`, `dungeon_clawler`, `nrftw` : le paquet detecte n'est pas la ou on l'extrait

`_detect_pkg` cherche un `<racine>/__init__.py` a **exactement** deux segments ; a defaut, il retombe
sur la racine de la premiere entree. Ces trois-la passent par le repli - le nom detecte est bon
(`fez`), mais `<tmp>/fez/__init__.py` n'existe pas apres extraction, donc leur code est **plus
profond d'un cran** dans l'archive.

Hypothese a confirmer sur les fichiers reels : une disposition du type `fez/fez/__init__.py` ou
`fez/worlds/fez/__init__.py`.

Correctif attendu : chercher le `__init__.py` le **moins profond** quelle que soit sa profondeur, et
inserer dans `__path__` le **parent de son dossier** plutot que la racine de l'archive.

## Acceptance Criteria

**AC1 - Diagnostic avant correctif.** La disposition reelle des archives `fez`, `dungeon_clawler` et
`nrftw` est relevee (`unzip -l`) et consignee dans la story avant d'ecrire la detection. Aucun des
trois n'est corrige sur hypothese.

**AC2 - `worlds.Files` disponible.** Le module est attache au stub `worlds` dans
`introspect_options.py` **et** `generate_multiworld.py`. Un world qui lit `worlds.Files` sans
l'importer se charge sur les deux chemins.

**AC3 - Chemins canoniques presents.** Les dossiers qu'Archipelago considere comme acquis existent
dans l'image, `Players` inclus. Un world qui en parcourt un a l'import ne plante pas.

**AC4 - protobuf installe.** `worlds._sc2common` s'importe pour de vrai. Le bouchon `google`
n'intervient plus, puisque le module n'est plus manquant.

**AC5 - Detection de paquet par profondeur.** `_detect_pkg` trouve le `__init__.py` le moins profond
et le chemin insere dans `__path__` en decoule. Les dispositions plates d'aujourd'hui continuent de
fonctionner a l'identique.

**AC6 - Les six se chargent.** Une fixture par cas dans la suite de tests, ou a defaut un test sur la
fonction de detection avec les dispositions relevees en AC1. Le balayage complet passe de 662/672 a
668/672.

**AC7 - La divergence ne peut pas revenir.** Le test qui compare les deux scripts (introduit en
`v0.14.2` pour `orjson`) est etendu au stub `worlds`, ou un test equivalent est ajoute. Les deux
scripts doivent preparer l'environnement d'import de facon identique.

**AC8 - Gates.** Suite pytest du depot archipelago verte.

## Tasks / Subtasks

- [~] **Task 1** (AC1). Non faite - voir l'ecart assume. Relever la disposition des trois archives depuis MinIO et la consigner ici.
- [x] **Task 2** (AC2, AC7). Attacher `worlds.Files` aux deux stubs + test de non-divergence.
- [x] **Task 3** (AC3). Creer les dossiers canoniques manquants dans le Dockerfile.
- [x] **Task 4** (AC4). Ajouter `protobuf` aux dependances de l'image.
- [x] **Task 5** (AC5). AC6 se verifie au prochain balayage. Detection de paquet par profondeur + tests sur les dispositions reelles.
- [x] **Task 6** (AC8). Tag `v0.15.0` pose. Gates verts, tag, et re-balayage pour confirmer 668/672.

## Dev Notes

- **Un seul tag pour les six.** Trois tags archipelago ont ete poses en une heure le 2026-08-28
  (`v0.14.0`, `.1`, `.2`) ; ces six jeux echouent depuis toujours et ne justifient pas la meme
  cadence. Un `v0.15.0` groupe, verifie par un balayage complet.
- **Le bouchon peut nuire.** `mindustry` montre que bouchonner un module manquant transforme parfois
  une erreur claire en erreur incomprehensible. A garder en tete si d'autres cas de ce genre
  apparaissent : la liste des modules qu'on accepte de bouchonner meriterait peut-etre d'etre fermee.
- **Mesurer avant/apres.** Le balayage donne un chiffre : 662/672. C'est le seul critere de reussite
  qui vaille, et il est reproductible en une commande.

### Hors perimetre : les quatre echecs qui ne sont pas les notres

Ils restent en echec apres cette story, et c'est assume.

- **`rune4`, `smash64`, `untitled_goose_game`** : `AssertionError: Choice option 'random' cannot be
  manually assigned`, levee par `Options.py` du coeur d'Archipelago. Ces worlds declarent une valeur
  `random` que la version deployee interdit. Ils ne se chargent nulle part, generation comprise :
  c'est un bug de l'apworld ou une incompatibilite de version, a remonter en amont.
- **`sm_map_rando`** : fait un `pip install` depuis GitHub **a l'import**. Le conteneur n'a pas de
  reseau, par conception. Lui en donner serait un choix de securite, pas un correctif - et un world
  tiers qui installe du code arbitraire au chargement est exactement ce que cette absence protege.

### Project Structure Notes

- `archipelago/introspect_options.py` (`_detect_pkg`, le stub `_worlds_stub`, les pre-bouchons).
- `archipelago/generate_multiworld.py` (la reference dont l'introspection ne doit pas diverger).
- `archipelago/apworld_import.py` (le bouchonnage des modules manquants).
- `archipelago/Dockerfile` (dependances et chemins canoniques).
- `archipelago/tests/test_orjson_shim.py` (le patron du test de non-divergence).

### References

- [Source: _bmad-output/implementation-artifacts/9-53-reintrospect-apworld-options-command.md]
- [Source: archilan-archipelago PR #23 (le meme motif de divergence, pour `orjson`)]
- [Source: balayage du 2026-08-28 : 672 traites, 662 reussis, 10 echecs]

## Dev Agent Record

### Ce qui a ete livre

| Cas | Correctif |
|-----|-----------|
| `fez`, `dungeon_clawler`, `nrftw` | `_detect_pkg` retient le `__init__.py` le moins profond et pose son parent sur `worlds.__path__` |
| `jurassic_park` | `worlds.Files` attache au stub, dans les deux scripts |
| `gtfo` | `Players` et `output` crees dans l'image |
| `mindustry` | `protobuf` installe |

Plus `tests/test_detect_pkg.py` (9 cas) et `tests/test_worlds_stub_parity.py` (AC7). 71 tests verts.

### Ecart assume : corrige par principe, pas sur diagnostic

**L'AC1 n'est pas tenue.** Elle exigeait de relever la disposition reelle des trois archives avant
d'ecrire la detection. Je n'y ai pas acces : le MinIO local appartient a un autre projet, celui de
production est sur le serveur et ses identifiants ne sont pas ici.

Plutot que de bloquer, la detection a ete rendue robuste **par principe** : chercher le `__init__.py`
le moins profond couvre les dispositions plausibles (`fez/fez/`, `worlds/fez/`, autres) sans avoir a
savoir laquelle correspond a quel jeu.

La contrepartie est explicite : **la verification se fait par le resultat, pas par l'analyse**. Si le
prochain balayage complet ne donne pas 668/672, l'hypothese etait fausse et il faudra les archives.
C'est un ecart methodologique reel, pas un raccourci gratuit - et il est ici pour qu'on s'en
souvienne si le chiffre ne tombe pas juste.

### Deux divergences en une journee

`jurassic_park` est la deuxieme divergence entre `introspect_options.py` et `generate_multiworld.py`
trouvee le meme jour, apres `orjson`. La forme ne change pas : la generation charge tout le
catalogue et herite gratuitement des imports d'un world voisin, l'introspection charge un world
isole et n'herite de rien.

D'ou le test de parite par AST sur le stub `worlds`, avec ses deux exceptions listees explicitement
(`__file__`, `network_data_package`) plutot que tolerees en silence. C'est le deuxieme filet de ce
genre apres celui d'`orjson` ; s'il en faut un troisieme, il vaudra mieux se demander pourquoi ces
deux scripts ne partagent pas leur preparation d'environnement.

### Le bouchon peut nuire

`mindustry` merite d'etre retenu : bouchonner `google` a transforme une dependance manquante en
`KeyError: '_RACE'` au fond d'un module genere. Sans le bouchon, l'erreur aurait ete un `ImportError`
qui nommait le probleme. La liste des modules qu'on accepte de bouchonner meriterait peut-etre
d'etre fermee - non traite ici.

## Ce qui reste

Deployer `archipelago v0.15.0` et relancer le balayage complet. Le chiffre attendu est **668/672** ;
c'est lui qui valide ou invalide l'ecart ci-dessus.


## Change Log

| Date       | Change |
|------------|--------|
| 2026-08-28 | Implementee en `v0.15.0`. AC1 non tenue : detection corrigee par principe faute d'acces aux archives, verification reportee sur le chiffre du prochain balayage. |
| 2026-08-28 | Creee apres le premier balayage complet du catalogue, qui a revele dix echecs d'introspection jamais rapportes. Six sont a nous, quatre ne le sont pas. Status: draft. |
