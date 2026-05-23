#!/usr/bin/env python3
"""Generate Archipelago YAML template for a given game."""
import argparse
import importlib
import importlib.abc
import importlib.machinery
import json as _json
import os
import pathlib
import sys
import types
import zipfile

# Kivy: suppress its argument parser and env-var hooks so it doesn't interfere
# with sys.argv (some worlds import kivy at module level for their client UI).
os.environ.setdefault("KIVY_NO_ARGS", "1")
os.environ.setdefault("KIVY_NO_ENV_CONFIG", "1")

ARCH_SRC = "/app/ArchipelagoSrc"

# ─── Specific pre-stubs ───────────────────────────────────────────────────────
# Placed into sys.modules BEFORE sys.path is modified so these stubs always win.

# ModuleUpdate: Archipelago's own auto-updater - no-op in ephemeral containers.
_mu = types.ModuleType("ModuleUpdate")
_mu.update = lambda *a, **kw: None  # type: ignore[attr-defined]
sys.modules["ModuleUpdate"] = _mu

# _winapi: Windows-only C extension imported unconditionally by Python 3.13's
# subprocess.py on some code paths. Returns 0 for any attribute lookup.
_winapi_stub = types.ModuleType("_winapi")
_winapi_stub.__getattr__ = lambda name: 0  # type: ignore[method-assign]
sys.modules["_winapi"] = _winapi_stub

# orjson: fast Rust JSON library used by the Factorio world for its data files.
_orjson = types.ModuleType("orjson")
_orjson.loads = _json.loads  # type: ignore[attr-defined]
_orjson.dumps = lambda obj, **kw: _json.dumps(obj, default=str).encode()  # type: ignore[attr-defined]
sys.modules["orjson"] = _orjson

# ─── Worlds stub + source tree ────────────────────────────────────────────────
# Inject a stub `worlds` package so importing worlds.AutoWorld does NOT trigger
# worlds/__init__.py's auto-loading of all games (which causes Logic Mixin
# conflicts when a custom apworld redefines the same game as a built-in world).
_worlds_stub = types.ModuleType("worlds")
_worlds_stub.__path__ = [f"{ARCH_SRC}/worlds"]
_worlds_stub.__package__ = "worlds"
sys.modules["worlds"] = _worlds_stub
sys.path.insert(0, ARCH_SRC)

parser = argparse.ArgumentParser()
parser.add_argument("--outputpath", required=True)
parser.add_argument("--world_directory", default=None)
args = parser.parse_args()

# Load framework without triggering worlds/__init__.py
from worlds.AutoWorld import AutoWorldRegister, World  # noqa: E402
from Utils import local_path, user_path  # noqa: E402

# Expose on the stub so other modules can do `from worlds import AutoWorldRegister`
_worlds_stub.AutoWorldRegister = AutoWorldRegister
_worlds_stub.World = World

# Expose worlds/__init__.py public symbols that apworlds import directly.
# Without these, apworlds doing `from worlds import user_folder` crash with
# ImportError even though their logic doesn't actually use the value.
_worlds_stub.local_folder = f"{ARCH_SRC}/worlds"
_user_folder = user_path("worlds") if user_path() != local_path() else user_path("custom_worlds")
try:
    os.makedirs(_user_folder, exist_ok=True)
except OSError:
    _user_folder = None
_worlds_stub.user_folder = _user_folder
_worlds_stub.failed_world_loads = []

# ─── Auto-stub: silence client-only third-party imports ──────────────────────
# Appended to the END of sys.meta_path so real finders get first priority.
# A module only reaches this finder when no real file was found.

_ARCHIP_ROOTS = frozenset({
    "BaseClasses",
    "entrance_rando",
    "Fill",
    "Generate",
    "Main",
    "MultiServer",
    "NetUtils",
    "Options",
    "Patch",
    "settings",
    "Utils",
    "WebHost",
    "worlds",
})


class _Stub:
    """
    Flexible no-op stub for auto-stubbed third-party C extensions.

    See generate_multiworld.py for rationale. Template generation loads one
    world at a time; worlds that need a real C extension to initialise will
    fail to load and stay absent from AutoWorldRegister.world_types.
    """
    def __getattr__(self, _n): return _Stub()
    def __call__(self, *a, **kw): return _Stub()
    def __getitem__(self, key): return _Stub()
    def __setitem__(self, key, value): pass
    def __delitem__(self, key): pass
    def __contains__(self, item): return False
    def __neg__(self): return _Stub()
    def __pos__(self): return _Stub()
    def __abs__(self): return _Stub()
    def __invert__(self): return _Stub()
    def __add__(self, o): return _Stub()
    def __radd__(self, o): return _Stub()
    def __sub__(self, o): return _Stub()
    def __rsub__(self, o): return _Stub()
    def __mul__(self, o): return _Stub()
    def __rmul__(self, o): return _Stub()
    def __truediv__(self, o): return _Stub()
    def __rtruediv__(self, o): return _Stub()
    def __floordiv__(self, o): return _Stub()
    def __rfloordiv__(self, o): return _Stub()
    def __mod__(self, o): return _Stub()
    def __rmod__(self, o): return _Stub()
    def __pow__(self, o, m=None): return _Stub()
    def __rpow__(self, o): return _Stub()
    def __matmul__(self, o): return _Stub()
    def __rmatmul__(self, o): return _Stub()
    def __and__(self, o): return _Stub()
    def __rand__(self, o): return _Stub()
    def __or__(self, o): return _Stub()
    def __ror__(self, o): return _Stub()
    def __xor__(self, o): return _Stub()
    def __rxor__(self, o): return _Stub()
    def __lshift__(self, o): return _Stub()
    def __rlshift__(self, o): return _Stub()
    def __rshift__(self, o): return _Stub()
    def __rrshift__(self, o): return _Stub()
    def __lt__(self, o): return False
    def __le__(self, o): return False
    def __gt__(self, o): return False
    def __ge__(self, o): return False
    def __eq__(self, o): return isinstance(o, _Stub)
    def __ne__(self, o): return not isinstance(o, _Stub)
    def __bool__(self): return False
    def __int__(self): return 0
    def __float__(self): return 0.0
    def __complex__(self): return 0j
    def __index__(self): return 0
    def __str__(self): return ""
    def __repr__(self): return "stub"
    def __bytes__(self): return b""
    def __hash__(self): return 0
    def __iter__(self): return iter([])
    def __len__(self): return 0
    def items(self): return {}.items()
    def values(self): return {}.values()
    def keys(self): return {}.keys()


class _AutoStubFinder(importlib.abc.MetaPathFinder, importlib.abc.Loader):
    def find_spec(self, fullname: str, path, target=None):
        if fullname.split(".")[0] in _ARCHIP_ROOTS:
            return None
        return importlib.machinery.ModuleSpec(fullname, self)

    def create_module(self, spec):
        return types.ModuleType(spec.name)

    def exec_module(self, module):
        module.__getattr__ = lambda _n: _Stub()


sys.meta_path.append(_AutoStubFinder())

# ── Phase 1: load custom apworlds ────────────────────────────────────────────
# Track pkg_names of apworlds we successfully load so we can filter by __module__.
_loaded_pkg_names: list[str] = []

# Strategy: add the apworld ZIP to _worlds_stub.__path__ then import as
# worlds.<pkg_name>. This makes importlib.resources.files("worlds.<pkg_name>")
# work correctly for apworlds that call it at module level.
if args.world_directory:
    for _apw in sorted(pathlib.Path(args.world_directory).glob("*.apworld")):
        try:
            with zipfile.ZipFile(str(_apw)) as zf:
                entries = zf.namelist()
                if not entries:
                    continue
                pkg_name = entries[0].split("/")[0]
        except Exception as exc:
            print(f"Warning: could not inspect {_apw.name}: {exc}", file=sys.stderr)
            continue

        if not pkg_name or not pkg_name.isidentifier():
            print(f"Warning: skipping {_apw.name}: invalid package name '{pkg_name}'", file=sys.stderr)
            continue

        world_mod_name = f"worlds.{pkg_name}"
        if world_mod_name in sys.modules:
            continue

        _worlds_stub.__path__.append(str(_apw))
        try:
            importlib.import_module(world_mod_name)
            _loaded_pkg_names.append(pkg_name)
        except Exception as exc:
            _worlds_stub.__path__.remove(str(_apw))
            print(f"Warning: failed to load {_apw.name} ({pkg_name}): {exc}", file=sys.stderr)

# ── Pick the game registered by the apworld ──────────────────────────────────
# Filter by __module__ to exclude built-in worlds pulled in as transitive imports
# (e.g. an apworld that imports worlds.archipelago would also register "Archipelago").
_apworld_prefixes = tuple(f"worlds.{p}" for p in _loaded_pkg_names)
apworld_games = [
    g for g, cls in AutoWorldRegister.world_types.items()
    if getattr(cls, "__module__", "").startswith(_apworld_prefixes)
]
if not apworld_games:
    print("No game registered from the apworld(s) in --world_directory", file=sys.stderr)
    sys.exit(1)
game = apworld_games[0]

# ── Generate template via Archipelago's official template engine ─────────────
# Filter world_types to only the target game so the function outputs one file.
_all_world_types = dict(AutoWorldRegister.world_types)
AutoWorldRegister.world_types.clear()
AutoWorldRegister.world_types[game] = _all_world_types[game]

output = pathlib.Path(args.outputpath)
output.mkdir(parents=True, exist_ok=True)

from Options import generate_yaml_templates  # noqa: E402
generate_yaml_templates(str(output))

# Restore world_types in case something downstream still needs them.
AutoWorldRegister.world_types.update(_all_world_types)

yaml_files = sorted(output.glob("*.yaml"))
if yaml_files:
    print(yaml_files[0].read_text(encoding="utf-8"))
else:
    print(f"No template generated for '{game}'", file=sys.stderr)
    sys.exit(1)
