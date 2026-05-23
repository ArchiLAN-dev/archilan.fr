#!/usr/bin/env python3
"""
Server-side Archipelago multiworld generator.

Uses Python 3.13 + Archipelago source tree so custom .apworld files compiled
for Python 3.13 are loaded correctly (the ArchipelagoGenerate binary embeds
Python 3.12 and cannot load them).

Stubs out client-only third-party C extensions that apworlds import at module
level but are never needed server-side (dolphin_memory_engine, gclib, …).
"""
import importlib.abc
import importlib.machinery
import json as _json
import os
import pathlib
import shutil
import sys
import tempfile
import types
import zipfile

# Kivy: suppress its argument parser and env-var hooks so it doesn't interfere
# with sys.argv (some worlds import kivy at module level for their client UI).
os.environ.setdefault("KIVY_NO_ARGS", "1")
os.environ.setdefault("KIVY_NO_ENV_CONFIG", "1")

ARCH_SRC          = "/app/ArchipelagoSrc"
OFFICIAL_APWORLDS = pathlib.Path("/app/Archipelago/Archipelago/lib/worlds")
APWORLDS_IN       = pathlib.Path("/apworlds")

# ─── Specific pre-stubs ───────────────────────────────────────────────────────

_mu = types.ModuleType("ModuleUpdate")
_mu.update = lambda *a, **kw: None  # type: ignore[attr-defined]
sys.modules["ModuleUpdate"] = _mu

_winapi_stub = types.ModuleType("_winapi")
_winapi_stub.__getattr__ = lambda name: 0  # type: ignore[method-assign]
sys.modules["_winapi"] = _winapi_stub

_orjson = types.ModuleType("orjson")
_orjson.loads = _json.loads  # type: ignore[attr-defined]
_orjson.dumps = lambda obj, **kw: _json.dumps(obj, default=str).encode()  # type: ignore[attr-defined]
sys.modules["orjson"] = _orjson

# pkg_resources: setuptools 71+ no longer ships it as a standalone top-level
# package. Pre-populate sys.modules from pip's vendored copy so apworlds that
# call pkg_resources.resource_listdir() get the real implementation.
try:
    import pkg_resources  # noqa: F401
except ImportError:
    from pip._vendor import pkg_resources as _pr  # type: ignore[no-redef]
    sys.modules["pkg_resources"] = _pr

# ─── Source tree ──────────────────────────────────────────────────────────────
sys.path.insert(0, ARCH_SRC)

# ─── Auto-stub: silence client-only third-party imports ──────────────────────
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

# ─── Run generation ──────────────────────────────────────────────────────────
if __name__ == "__main__":
    import Generate
    import worlds as _worlds_pkg
    from Main import main as ERmain

    def _load_apworlds_from(apworld_dir: pathlib.Path) -> None:
        if not apworld_dir.is_dir():
            return
        for _apw in sorted(apworld_dir.glob("*.apworld")):
            try:
                with zipfile.ZipFile(str(_apw)) as _zf:
                    _entries = _zf.namelist()
                    _pkg = _entries[0].split("/")[0] if _entries else None
            except Exception as _e:
                print(f"Warning: could not inspect {_apw.name}: {_e}", file=sys.stderr)
                continue
            if not _pkg or not _pkg.isidentifier():
                print(f"Warning: skipping {_apw.name}: invalid package name", file=sys.stderr)
                continue
            _mod = f"worlds.{_pkg}"
            if _mod in sys.modules:
                continue
            _worlds_pkg.__path__.append(str(_apw))
            try:
                importlib.import_module(_mod)
            except Exception as _e:
                _worlds_pkg.__path__.remove(str(_apw))
                print(f"Warning: failed to load {_apw.name} ({_pkg}): {_e}", file=sys.stderr)

    # Consume --world_directory and strip it from sys.argv so Generate.main()
    # does not see it as an unrecognised argument.
    _custom_dir: pathlib.Path | None = None
    _i = 1
    while _i < len(sys.argv):
        if sys.argv[_i] == "--world_directory" and _i + 1 < len(sys.argv):
            _custom_dir = pathlib.Path(sys.argv[_i + 1])
            del sys.argv[_i:_i + 2]
        else:
            _i += 1

    # Build a combined apworlds directory:
    #   1. Official apworlds from the binary release (always present, even if unused)
    #   2. Custom apworlds from /apworlds volume (if mounted)
    #   3. Custom apworlds from --world_directory (session-specific)
    # Custom files with the same name as an official one override it.
    _combined = pathlib.Path(tempfile.mkdtemp(prefix="apworlds_"))

    for _apw in sorted(OFFICIAL_APWORLDS.glob("*.apworld")):
        shutil.copy2(_apw, _combined / _apw.name)

    for _src in [APWORLDS_IN, _custom_dir]:
        if _src and _src.is_dir():
            for _apw in sorted(_src.glob("*.apworld")):
                shutil.copy2(_apw, _combined / _apw.name)

    print(f"DEBUG combined apworlds: {sorted(p.name for p in _combined.glob('*.apworld'))}",
          file=sys.stderr)

    _load_apworlds_from(_combined)

    # Rebuild network_data_package to include worlds loaded after initial import
    _worlds_pkg.network_data_package["games"].update({
        cls.game: cls.get_data_package_data()
        for cls in _worlds_pkg.AutoWorldRegister.world_types.values()
    })

    print(f"DEBUG worlds loaded: {sorted(__import__('worlds').AutoWorldRegister.world_types)}",
          file=sys.stderr)

    erargs, seed = Generate.main()
    ERmain(erargs, seed)

    # Print the generated output filename to stdout for the orchestrator to capture.
    _out_dir = pathlib.Path(getattr(erargs, "outputpath", "/data/output"))
    _out_files = sorted(_out_dir.glob("*.zip")) or sorted(_out_dir.glob("*.archipelago"))
    if _out_files:
        print(_out_files[0].name, flush=True)
    else:
        print(f"ERROR: no output file found in {_out_dir}", file=sys.stderr)
        sys.exit(1)
