#!/usr/bin/env python3
"""
Introspect Archipelago apworld option types and emit JSON to stdout.

Output: {"options": {"option_key": {"type": "range|choice|toggle|text|weights",
                                    "defaultWeights": {key: int}}}}

"defaultWeights" is only present for "weights" (OptionDict) options.
"""
import argparse
import atexit
import importlib
import importlib.abc
import importlib.machinery
import json
import os
import pathlib
import shutil
import sys
import tempfile
import traceback
import types
import zipfile

# Kivy: suppress its argument parser and env-var hooks.
os.environ.setdefault("KIVY_NO_ARGS", "1")
os.environ.setdefault("KIVY_NO_ENV_CONFIG", "1")

ARCH_SRC = "/app/ArchipelagoSrc"

# ─── Specific pre-stubs ───────────────────────────────────────────────────────

_mu = types.ModuleType("ModuleUpdate")
_mu.update = lambda *a, **kw: None  # type: ignore[attr-defined]
sys.modules["ModuleUpdate"] = _mu

_winapi_stub = types.ModuleType("_winapi")
_winapi_stub.__getattr__ = lambda name: 0  # type: ignore[method-assign]
sys.modules["_winapi"] = _winapi_stub

import json as _json
_orjson = types.ModuleType("orjson")
_orjson.loads = _json.loads  # type: ignore[attr-defined]
_orjson.dumps = lambda obj, **kw: _json.dumps(obj, default=str).encode()  # type: ignore[attr-defined]
sys.modules["orjson"] = _orjson

try:
    import pkg_resources  # noqa: F401
except ImportError:
    from pip._vendor import pkg_resources as _pr  # type: ignore[no-redef]
    sys.modules["pkg_resources"] = _pr

# ─── Worlds stub + source tree ────────────────────────────────────────────────

_worlds_stub = types.ModuleType("worlds")
_worlds_stub.__path__ = [f"{ARCH_SRC}/worlds"]
_worlds_stub.__package__ = "worlds"
sys.modules["worlds"] = _worlds_stub
sys.path.insert(0, ARCH_SRC)

parser = argparse.ArgumentParser()
parser.add_argument("--world_directory", required=True)
args = parser.parse_args()

from worlds.AutoWorld import AutoWorldRegister, World  # noqa: E402
from Utils import local_path, user_path  # noqa: E402

_worlds_stub.AutoWorldRegister = AutoWorldRegister
_worlds_stub.World = World
_worlds_stub.local_folder = f"{ARCH_SRC}/worlds"
_user_folder = user_path("worlds") if user_path() != local_path() else user_path("custom_worlds")
try:
    os.makedirs(_user_folder, exist_ok=True)
except OSError:
    _user_folder = None
_worlds_stub.user_folder = _user_folder
_worlds_stub.failed_world_loads = []

# ─── Auto-stub: silence client-only third-party imports ──────────────────────

_ARCHIP_ROOTS = frozenset({
    "BaseClasses", "entrance_rando", "Fill", "Generate", "Main",
    "MultiServer", "NetUtils", "Options", "Patch", "settings",
    "Utils", "WebHost", "worlds",
})


class _Stub:
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
    def __iter__(self): return iter([_Stub()])
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

# ── Load custom apworld ───────────────────────────────────────────────────────

_loaded_pkg_names: list[str] = []

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

    _tmp_dir = tempfile.mkdtemp(prefix="apworld_")
    atexit.register(shutil.rmtree, _tmp_dir, True)
    with zipfile.ZipFile(str(_apw)) as _zf:
        _zf.extractall(_tmp_dir)

    _worlds_stub.__path__.insert(0, _tmp_dir)
    try:
        importlib.import_module(world_mod_name)
        _loaded_pkg_names.append(pkg_name)
    except Exception as exc:
        _worlds_stub.__path__.remove(_tmp_dir)
        print(f"Warning: failed to load {_apw.name} ({pkg_name}): {exc}", file=sys.stderr)
        traceback.print_exc(file=sys.stderr)

# ── Find registered game ──────────────────────────────────────────────────────

_apworld_prefixes = tuple(f"worlds.{p}" for p in _loaded_pkg_names)
apworld_games = [
    g for g, cls in AutoWorldRegister.world_types.items()
    if getattr(cls, "__module__", "").startswith(_apworld_prefixes)
]
if not apworld_games:
    print("No game registered from the apworld(s) in --world_directory", file=sys.stderr)
    sys.exit(1)

game = apworld_games[0]
world_cls = AutoWorldRegister.world_types[game]

# ── Classify option types ─────────────────────────────────────────────────────

# Import base option classes; gracefully skip any that don't exist in this AP version.
def _try_import(name: str):
    try:
        mod = importlib.import_module("Options")
        return getattr(mod, name, None)
    except Exception:
        return None

_OptionDict = _try_import("OptionDict")
_Toggle     = _try_import("Toggle")
_Choice     = _try_import("Choice")
_Range      = _try_import("Range")
_OptionList = _try_import("OptionList")
_FreeText   = _try_import("FreeText")


def classify(field_type: type) -> str | None:
    """Map a Python option class to our TemplateOptionType string."""
    if not isinstance(field_type, type):
        return None
    try:
        # OptionDict before Choice — OptionDict may not inherit from Choice but
        # check order matters if a future version changes the hierarchy.
        if _OptionDict and issubclass(field_type, _OptionDict):
            return "weights"
        # Toggle before Choice — Toggle subclasses Choice in some AP versions.
        if _Toggle and issubclass(field_type, _Toggle):
            return "toggle"
        if _Choice and issubclass(field_type, _Choice):
            return "choice"
        if _Range and issubclass(field_type, _Range):
            return "range"
        if _OptionList and issubclass(field_type, _OptionList):
            return "text"
        if _FreeText and issubclass(field_type, _FreeText):
            return "text"
    except TypeError:
        pass
    return None


# ── Inspect options_dataclass ─────────────────────────────────────────────────

import dataclasses
import typing

result: dict[str, dict] = {}

if hasattr(world_cls, "options_dataclass") and world_cls.options_dataclass is not None:
    try:
        hints = typing.get_type_hints(world_cls.options_dataclass)
    except Exception:
        hints = {}

    for field in dataclasses.fields(world_cls.options_dataclass):
        # Prefer resolved hint; fall back to raw field.type if it's not a string annotation.
        field_type = hints.get(field.name)
        if field_type is None and not isinstance(field.type, str):
            field_type = field.type
        if field_type is None:
            continue

        typ = classify(field_type)
        if typ is None:
            continue

        entry: dict = {"type": typ}

        if typ == "weights":
            try:
                default = field_type.default
                if isinstance(default, dict):
                    entry["defaultWeights"] = {str(k): int(v) for k, v in default.items()}
            except Exception:
                pass

        result[field.name] = entry

print(json.dumps({"options": result}))
