from __future__ import annotations

import re
from typing import Any

SLOT_MAX_LEN = 16

_STOP_WORDS = frozenset({"a", "an", "the", "of", "to", "in", "for", "and", "or", "at", "by"})


def abbreviate_game(name: str) -> str:
    """Return uppercase initials of significant words in the game name, max 4 chars."""
    words = re.sub(r"[^a-zA-Z0-9\s]", "", name).split()
    significant = [w for w in words if w.lower() not in _STOP_WORDS] or words
    abbr = "".join(w[0].upper() for w in significant if w)
    return abbr[:4] if abbr else "G"


def sanitize_player_name(name: str) -> str:
    """Keep only alphanumeric characters; fall back to 'Player'."""
    sanitized = re.sub(r"[^a-zA-Z0-9]", "", name)
    return sanitized if sanitized else "Player"


def generate_slot_names(slots: list[dict[str, Any]]) -> dict[str, str]:
    """
    Assign a unique ≤16-char slot name to each slot.

    Each slot dict must contain: slotId, playerName, archipelagoGameName.
    Names follow the pattern {player}_{abbr}{n}, e.g. 'Alice_HK1'.
    The index n increments per (player, game) pair; the while-loop resolves
    the rare collision where different players sanitize to the same prefix.
    """
    used: set[str] = set()
    per_pair_index: dict[tuple[str, str], int] = {}
    result: dict[str, str] = {}

    for slot in slots:
        player_base = sanitize_player_name(slot["playerName"])
        abbr = abbreviate_game(slot["archipelagoGameName"])
        pair = (player_base, abbr)

        per_pair_index[pair] = per_pair_index.get(pair, 0) + 1
        idx = per_pair_index[pair]

        candidate = _build(player_base, abbr, idx)
        while candidate in used:
            idx += 1
            candidate = _build(player_base, abbr, idx)

        used.add(candidate)
        result[slot["slotId"]] = candidate

    return result


def _build(player: str, abbr: str, n: int) -> str:
    counter = str(n)
    suffix = f"_{abbr}{counter}"
    if len(suffix) >= SLOT_MAX_LEN:
        suffix = f"_{counter}"
    if len(suffix) >= SLOT_MAX_LEN:
        return counter[-SLOT_MAX_LEN:]
    max_player = max(1, SLOT_MAX_LEN - len(suffix))
    return f"{player[:max_player]}{suffix}"


def validate_slot_names(names: list[str]) -> list[str]:
    """
    Return a list of error messages for a set of confirmed slot names.
    Checks: each name ≤ 16 chars, all names unique.
    """
    errors: list[str] = []
    seen: set[str] = set()
    for name in names:
        if len(name) > SLOT_MAX_LEN:
            errors.append(f"Le nom de slot '{name}' dépasse {SLOT_MAX_LEN} caractères.")
        if name in seen:
            errors.append(f"Le nom de slot '{name}' est utilisé plusieurs fois.")
        seen.add(name)
    return errors
