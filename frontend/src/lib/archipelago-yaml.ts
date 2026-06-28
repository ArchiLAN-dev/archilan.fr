import * as yaml from "js-yaml";

// ─── Constants ────────────────────────────────────────────────────────────────

export const RANDOM_ALIASES = [
  { key: "random", label: "Aléatoire (uniforme)" },
  { key: "random-low", label: "Aléatoire bas" },
  { key: "random-middle", label: "Aléatoire milieu" },
  { key: "random-high", label: "Aléatoire haut" },
] as const;

export type RandomAliasKey = (typeof RANDOM_ALIASES)[number]["key"];

const FIXED_ALIAS_KEYS = new Set<string>(RANDOM_ALIASES.map((r) => r.key));

// random-range[-low|-middle|-high]-<min>-<max>
const RANGE_ALIAS_RE = /^random-range(-low|-middle|-high)?-(-?\d+)-(-?\d+)$/;

function isRandomAlias(k: string): boolean {
  return FIXED_ALIAS_KEYS.has(k) || RANGE_ALIAS_RE.test(k);
}

// Keys always rendered as freeform dicts regardless of value shape
const FREEFORM_DICT_KEYS = new Set(["start_inventory", "start_inventory_from_pool"]);

// Authoritative range bounds + default per option key, from apworld introspection (story 9.25).
// Supplied by the API; preferred over template-comment scraping when present.
export type OptionBounds = { min: number; max: number; default: number | null };
export type OptionTypesMap = Record<string, OptionBounds>;

/** Validates an API `optionTypes` payload (unknown) into an OptionTypesMap, or null. */
export function asOptionTypesMap(value: unknown): OptionTypesMap | null {
  if (typeof value !== "object" || value === null) return null;
  const out: OptionTypesMap = {};
  for (const [key, raw] of Object.entries(value as Record<string, unknown>)) {
    if (typeof raw !== "object" || raw === null) continue;
    const b = raw as Record<string, unknown>;
    if (typeof b.min === "number" && typeof b.max === "number") {
      out[key] = { min: b.min, max: b.max, default: typeof b.default === "number" ? b.default : null };
    }
  }
  return Object.keys(out).length > 0 ? out : null;
}

// Top-level keys that are never shown to the player
const METADATA_KEYS = new Set(["name", "description", "game", "requires"]);

// All known top-level keys - used to identify the game block by exclusion
const STANDARD_KEYS = new Set([
  "name", "description", "game", "requires",
  "accessibility", "progression_balancing",
  "local_items", "non_local_items", "start_inventory",
  "exclude_locations", "include_locations",
  "start_hints", "start_location_hints", "exclude_item_groups",
]);

// ─── Types ────────────────────────────────────────────────────────────────────

export type TextOption = {
  type: "text";
  key: string;
  label: string;
  value: string;
  description?: string;
  category?: string;
};

export type ToggleOption = {
  type: "toggle";
  key: string;
  label: string;
  weightFalse: number;
  weightTrue: number;
  description?: string;
  category?: string;
};

export type WeightedChoice = { value: string; weight: number; description?: string };

export type ChoiceOption = {
  type: "choice";
  key: string;
  label: string;
  choices: WeightedChoice[];
  description?: string;
  category?: string;
};

export type RangeEntry = {
  id: string;
  key: string;
  weight: number;
  isCustom: boolean;
  description?: string;
};

export type RangeOption = {
  type: "range";
  key: string;
  label: string;
  min: number;
  max: number;
  entries: RangeEntry[];
  description?: string;
  category?: string;
};

export type FreeformListOption = {
  type: "freeform";
  kind: "list";
  key: string;
  label: string;
  items: string[];
  description?: string;
  category?: string;
};

export type FreeformDictEntry = { id: string; k: string; v: string };

export type FreeformDictOption = {
  type: "freeform";
  kind: "dict";
  key: string;
  label: string;
  entries: FreeformDictEntry[];
  /**
   * When true, the keys come from a fixed schema (e.g. Pokemon `game_options`): the editor locks
   * the key names and forbids adding/removing rows - only the values are editable. Free dicts the
   * player composes (e.g. `start_inventory`) leave this unset.
   */
  fixedKeys?: boolean;
  description?: string;
  category?: string;
};

export type FreeformOption = FreeformListOption | FreeformDictOption;

export type PlandoItemRow = { id: string; name: string; quantity: number };
export type PlandoLocationRow = { id: string; value: string };

// world: "own" → false, "any" → true, "random" → null, other string → player name
export type PlandoItem = {
  id: string;
  items: PlandoItemRow[];
  locations: PlandoLocationRow[];
  world: string;
  fromPool: boolean;
  force: "true" | "false" | "silent";
  percentage: number;
};

export type PlandoItemsOption = {
  type: "plando_items";
  key: string;
  label: string;
  entries: PlandoItem[];
  description?: string;
  category?: string;
};

export type ItemLinkEntry = {
  id: string;
  name: string;
  itemPool: string[];
  replacementItem: string | null;
  linkReplacement: boolean;
  localItems: string[];
  nonLocalItems: string[];
};

export type ItemLinksOption = {
  type: "item_links";
  key: string;
  label: string;
  entries: ItemLinkEntry[];
  description?: string;
  category?: string;
};

export type GameOption = TextOption | ToggleOption | ChoiceOption | RangeOption | FreeformOption | PlandoItemsOption | ItemLinksOption;

export type ParsedYaml = {
  rawDoc: Record<string, unknown>;
  playerName: string;
  playerNameDescription?: string;
  gameName: string;
  options: GameOption[];
  /** Keys of options that live at the top level of the YAML (not under the game block) */
  topLevelOptionKeys: ReadonlySet<string>;
};

// ─── Slot name validation ──────────────────────────────────────────────────────

/** Archipelago slot-name limit (mirrors the backend `SlotName::MAX_LENGTH`). */
export const SLOT_NAME_MAX_LENGTH = 16;

/**
 * A slot/player name (the YAML `name:`) may only contain letters, digits, underscore and the AP
 * placeholders {number}/{player} (and uppercase variants), substituted per slot. Everything else
 * (apostrophes, spaces, accents, …) is rejected - it breaks generation and in-game display.
 */
const SLOT_NAME_PATTERN = /^(?:[A-Za-z0-9_]|\{(?:number|player|NUMBER|PLAYER)\})+$/;

export function isValidSlotName(name: string): boolean {
  return name.length > 0 && name.length <= SLOT_NAME_MAX_LENGTH && SLOT_NAME_PATTERN.test(name);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

export function labelFromKey(key: string): string {
  return key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function labelFromAlias(key: string): string | null {
  const fixed = RANDOM_ALIASES.find((r) => r.key === key);
  if (fixed) return fixed.label;
  const m = RANGE_ALIAS_RE.exec(key);
  if (!m) return null;
  const bias = m[1] === "-low" ? " bas" : m[1] === "-middle" ? " milieu" : m[1] === "-high" ? " haut" : "";
  return `Aléatoire${bias} [${m[2]}–${m[3]}]`;
}

function clampWeight(val: unknown): number {
  return typeof val === "number" ? Math.max(0, Math.min(100, Math.round(val))) : 0;
}

let _uid = 0;
function uid(): string {
  return `ap-entry-${++_uid}`;
}

// Returns the raw comment block (lines starting with #) adjacent to a key.
// Checks after the key first (game-block style), then before (top-level style).
function extractCommentBlock(yamlStr: string, key: string): string | null {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const after = yamlStr.match(new RegExp(`${escaped}:[^\n]*\n((?:[ \t]*#[^\n]*\n)+)`));
  if (after) return after[1];
  const before = yamlStr.match(new RegExp(`((?:[ \t]*#[^\n]*\n)+)[ \t]*${escaped}:`));
  if (before) return before[1];
  return null;
}

function extractDescription(yamlStr: string, key: string): string | undefined {
  const block = extractCommentBlock(yamlStr, key);
  if (!block) return undefined;
  const lines = block
    .split("\n")
    .map((l) => l.replace(/^\s*#\s?/, ""))
    .filter((l) => l.trim() !== "" && !/^#+$/.test(l.trim()));
  return lines.join("\n").trim() || undefined;
}

function extractRange(yamlStr: string, key: string): { min: number; max: number } | null {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  // Wide block after the key covers inline comment blocks (game-block style)
  const wideAfter = yamlStr.match(new RegExp(`${escaped}:[\\s\\S]{0,600}`));
  const commentBlock = extractCommentBlock(yamlStr, key);

  for (const block of [wideAfter?.[0] ?? "", commentBlock ?? ""]) {
    if (!block) continue;
    const rangeM = block.match(/#\s*Range:\s*(-?\d+)\s*-\s*(-?\d+)/);
    if (rangeM) return { min: parseInt(rangeM[1], 10), max: parseInt(rangeM[2], 10) };
    const minM = block.match(/#\s*[Mm]inimum value is\s*(-?\d+)/);
    const maxM = block.match(/#\s*[Mm]aximum value is\s*(-?\d+)/);
    if (minM && maxM) return { min: parseInt(minM[1], 10), max: parseInt(maxM[1], 10) };
  }
  return null;
}

// Returns inline comments for each value under an option key, e.g.
//   random-low: 0 # random value weighted towards lower values
// → Map { "random-low" → "random value weighted towards lower values" }
function extractValueComments(yamlStr: string, key: string): Map<string, string> {
  const result = new Map<string, string>();
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

  const keyLineMatch = yamlStr.match(new RegExp(`^([ \\t]*)${escaped}:[ \\t]*$`, "m"));
  if (!keyLineMatch || keyLineMatch.index === undefined) return result;

  const keyIndent = keyLineMatch[1].length;
  const afterKey = yamlStr.slice(keyLineMatch.index + keyLineMatch[0].length);

  for (const line of afterKey.split("\n").slice(1)) {
    if (line.trim() === "") continue;
    const lineIndent = (line.match(/^([ \t]*)/) ?? ["", ""])[1].length;
    if (lineIndent <= keyIndent) break;

    const m = line.match(/^[ \t]+('.*?'|".*?"|[^\s:#][^\s:]*):\s*[^\n#]*#\s*(.+)$/);
    if (m) result.set(m[1].replace(/^['"]|['"]$/g, ""), m[2].trim());
  }

  return result;
}

// ─── Category extraction ─────────────────────────────────────────────────────

function parseCategories(yamlStr: string, gameName: string): Map<string, string> {
  const result = new Map<string, string>();
  const escaped = gameName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const gameLineMatch = yamlStr.match(new RegExp(`^${escaped}:\\s*$`, "m"));
  if (!gameLineMatch || gameLineMatch.index === undefined) return result;

  const lines = yamlStr.slice(gameLineMatch.index + gameLineMatch[0].length).split("\n");
  let pendingCategory: string | null = null;
  let pendingLines: string[] = [];

  for (const line of lines) {
    if (/^[^\s]/.test(line) && line.trim() !== "") break;

    const optionMatch = line.match(/^  ([a-zA-Z_][a-zA-Z0-9_]*):\s*$/);
    if (optionMatch) {
      if (pendingLines.length > 0) {
        const text = pendingLines
          .map((l) => l.replace(/^\s*#+\s*/, "").replace(/\s*#+\s*$/, "").trim())
          .filter((l) => l !== "" && !/^[=\-*~^_]+$/.test(l))
          .join(" ")
          .trim();
        if (text) pendingCategory = text;
        pendingLines = [];
      }
      if (pendingCategory) result.set(optionMatch[1], pendingCategory);
      continue;
    }

    if (/^  #/.test(line)) { pendingLines.push(line); continue; }
    if (/^    /.test(line)) pendingLines = [];
  }

  return result;
}

// ─── Plando items parsing ─────────────────────────────────────────────────────

function parsePlandoEntries(value: unknown): PlandoItem[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((raw) => {
    if (!raw || typeof raw !== "object") return [];
    const block = raw as Record<string, unknown>;

    const itemsRaw = block["items"] ?? block["item"];
    let itemRows: PlandoItemRow[] = [];
    if (typeof itemsRaw === "string") {
      itemRows = [{ id: uid(), name: itemsRaw, quantity: 1 }];
    } else if (Array.isArray(itemsRaw)) {
      // AP accepts items as a plain list of strings (each placed once)
      itemRows = itemsRaw.map((n) => ({ id: uid(), name: typeof n === "string" ? n : String(n ?? ""), quantity: 1 }));
    } else if (itemsRaw && typeof itemsRaw === "object") {
      itemRows = Object.entries(itemsRaw as Record<string, unknown>).map(([name, qty]) => ({
        id: uid(), name, quantity: typeof qty === "number" ? qty : 1,
      }));
    }

    const locsRaw = block["locations"] ?? block["location"];
    let locationRows: PlandoLocationRow[] = [];
    if (typeof locsRaw === "string") {
      locationRows = [{ id: uid(), value: locsRaw }];
    } else if (Array.isArray(locsRaw)) {
      locationRows = locsRaw.map((l) => ({ id: uid(), value: typeof l === "string" ? l : String(l ?? "") }));
    }

    const worldRaw = block["world"];
    let world = "own";
    if (worldRaw === true) world = "any";
    else if (worldRaw === null) world = "random";
    else if (typeof worldRaw === "string") world = worldRaw;

    const forceRaw = block["force"];
    let force: "true" | "false" | "silent" = "silent";
    if (forceRaw === true || forceRaw === "true") force = "true";
    else if (forceRaw === false || forceRaw === "false") force = "false";

    const fromPool = block["from_pool"] !== false;

    const percentage =
      typeof block["percentage"] === "number"
        ? Math.max(0, Math.min(100, Math.round(block["percentage"])))
        : 100;

    return [{ id: uid(), items: itemRows, locations: locationRows, world, fromPool, force, percentage }];
  });
}

// ─── Item links parsing ───────────────────────────────────────────────────────

function parseItemLinkEntries(value: unknown): ItemLinkEntry[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((raw) => {
    if (!raw || typeof raw !== "object") return [];
    const block = raw as Record<string, unknown>;

    const name = typeof block["name"] === "string" ? block["name"] : "";

    const poolRaw = block["item_pool"];
    const itemPool: string[] = Array.isArray(poolRaw)
      ? poolRaw.map((i) => (typeof i === "string" ? i : String(i ?? "")))
      : [];

    const repRaw = block["replacement_item"];
    const replacementItem =
      repRaw === null || repRaw === undefined
        ? null
        : typeof repRaw === "string"
          ? repRaw
          : null;

    const linkReplacement = block["link_replacement"] === true;

    const localItems: string[] = Array.isArray(block["local_items"])
      ? (block["local_items"] as unknown[]).map((i) => (typeof i === "string" ? i : String(i ?? "")))
      : [];

    const nonLocalItems: string[] = Array.isArray(block["non_local_items"])
      ? (block["non_local_items"] as unknown[]).map((i) => (typeof i === "string" ? i : String(i ?? "")))
      : [];

    return [{ id: uid(), name, itemPool, replacementItem, linkReplacement, localItems, nonLocalItems }];
  });
}

// ─── Option type detection ────────────────────────────────────────────────────

function buildOption(key: string, value: unknown, yamlStr: string, optionTypes?: OptionTypesMap | null): GameOption {
  const label = labelFromKey(key);
  const description = extractDescription(yamlStr, key);

  // Scalar value: a scalar option that introspection knows to be a range is a range
  // (covers e.g. progression_balancing); otherwise it's free text.
  if (typeof value !== "object" || value === null) {
    const introspected = optionTypes?.[key];
    if (introspected && !isNaN(Number(value))) {
      return {
        type: "range", key, label,
        min: introspected.min, max: introspected.max,
        entries: [createRangeEntry(String(Math.round(Number(value))), 50, false)],
        description,
      };
    }
    return { type: "text", key, label, value: String(value ?? ""), description };
  }

  // Plando items: array of structured blocks - must check before generic Array.isArray
  if (key === "plando_items") {
    return {
      type: "plando_items", key, label,
      entries: parsePlandoEntries(value),
      description,
    };
  }

  // Item links: array of structured blocks - must check before generic Array.isArray
  if (key === "item_links") {
    return {
      type: "item_links", key, label,
      entries: parseItemLinkEntries(value),
      description,
    };
  }

  // Array → freeform list
  if (Array.isArray(value)) {
    return {
      type: "freeform", kind: "list", key, label,
      items: value.map((i) => (typeof i === "string" ? i : String(i ?? ""))),
      description,
    };
  }

  const obj = value as Record<string, unknown>;
  const keys = Object.keys(obj);

  // Known dict keys or empty object → freeform dict
  if (FREEFORM_DICT_KEYS.has(key) || keys.length === 0) {
    return {
      type: "freeform", kind: "dict", key, label,
      entries: keys.map((k) => ({ id: uid(), k, v: String(obj[k] ?? "") })),
      description,
    };
  }

  // Literal dict option (e.g. Pokemon `game_options`): a mapping of named sub-settings to
  // literal values, NOT a weighted distribution. Weighted options (toggle/choice/range)
  // always carry numeric weights as values; a non-numeric value means this is a literal
  // dict. Misclassifying it as a weighted `choice` runs every value through `clampWeight`,
  // which coerces non-numbers to 0 - turning `default_player_name: player_name` into
  // `default_player_name: 0` (an int), which crashes apworld generation downstream
  // ("TypeError: 'int' object is not iterable").
  if (keys.some((k) => typeof obj[k] !== "number")) {
    return {
      type: "freeform", kind: "dict", key, label,
      entries: keys.map((k) => ({ id: uid(), k, v: String(obj[k] ?? "") })),
      fixedKeys: true,
      description,
    };
  }

  // Toggle: keys are a subset of { "true", "false" }
  if (keys.every((k) => k === "true" || k === "false")) {
    return {
      type: "toggle", key, label,
      weightFalse: clampWeight(obj["false"]),
      weightTrue: clampWeight(obj["true"]),
      description,
    };
  }

  // Range: all keys are numeric or random aliases
  if (keys.every((k) => !isNaN(Number(k)) || isRandomAlias(k))) {
    // Authoritative introspected bounds first; template-comment scraping is the
    // fallback for apworlds not yet backfilled (story 9.25).
    const bounds = optionTypes?.[key] ?? extractRange(yamlStr, key) ?? { min: 0, max: 100 };
    const valueComments = extractValueComments(yamlStr, key);
    const fixedAliasEntries: RangeEntry[] = RANDOM_ALIASES.filter((r) => r.key in obj).map((r) => ({
      id: uid(), key: r.key, weight: clampWeight(obj[r.key]), isCustom: false,
      description: valueComments.get(r.key),
    }));
    const paramAliasEntries: RangeEntry[] = keys
      .filter((k) => RANGE_ALIAS_RE.test(k))
      .sort()
      .map((k) => ({ id: uid(), key: k, weight: clampWeight(obj[k]), isCustom: false, description: valueComments.get(k) }));
    const numericEntries: RangeEntry[] = keys
      .filter((k) => !isNaN(Number(k)))
      .sort((a, b) => Number(a) - Number(b))
      .map((k) => ({ id: uid(), key: k, weight: clampWeight(obj[k]), isCustom: false, description: valueComments.get(k) }));
    return {
      type: "range", key, label,
      min: bounds.min, max: bounds.max,
      entries: [...fixedAliasEntries, ...paramAliasEntries, ...numericEntries],
      description,
    };
  }

  // Choice: string keys with weights
  const valueComments = extractValueComments(yamlStr, key);
  return {
    type: "choice", key, label,
    choices: keys.map((k) => ({ value: k, weight: clampWeight(obj[k]), description: valueComments.get(k) })),
    description,
  };
}

// ─── Player value merge ───────────────────────────────────────────────────────

/**
 * Merges player-saved option weights onto the default YAML structure.
 * The base (from defaultYaml) provides all type/category/description info.
 * The player provides weights/values.
 */
export function mergePlayerValues(base: ParsedYaml, player: ParsedYaml): ParsedYaml {
  const playerByKey = new Map(player.options.map((o) => [o.key, o]));

  const mergedOptions = base.options.map((baseOpt): GameOption => {
    const playerOpt = playerByKey.get(baseOpt.key);
    if (!playerOpt || baseOpt.type !== playerOpt.type) return baseOpt;

    if (baseOpt.type === "text" && playerOpt.type === "text") {
      return { ...baseOpt, value: playerOpt.value };
    }

    if (baseOpt.type === "toggle" && playerOpt.type === "toggle") {
      return { ...baseOpt, weightFalse: playerOpt.weightFalse, weightTrue: playerOpt.weightTrue };
    }

    if (baseOpt.type === "choice" && playerOpt.type === "choice") {
      const playerWeights = new Map(playerOpt.choices.map((c) => [c.value, c.weight]));
      return {
        ...baseOpt,
        choices: baseOpt.choices.map((c) => ({
          ...c,
          weight: playerWeights.has(c.value) ? (playerWeights.get(c.value) ?? 0) : 0,
        })),
      };
    }

    if (baseOpt.type === "range" && playerOpt.type === "range") {
      // The saved override is authoritative for which values exist: defaults the admin removed are
      // absent from it and must NOT be re-injected (else they reappear at 0%), and custom values the
      // admin added must be preserved. We can't rely on the `isCustom` flag here - it doesn't survive
      // the serialize→save→re-parse round-trip - so we drive from the saved entries and re-attach the
      // default metadata (description) by key. A saved value with no matching default is a custom one.
      const baseByKey = new Map(baseOpt.entries.map((e) => [e.key, e]));
      const mergedEntries = playerOpt.entries.map((e) => {
        const baseEntry = baseByKey.get(e.key);

        return baseEntry ? { ...baseEntry, weight: e.weight } : { ...e, isCustom: true };
      });

      return { ...baseOpt, entries: mergedEntries };
    }

    if (baseOpt.type === "plando_items" && playerOpt.type === "plando_items") {
      return { ...baseOpt, entries: playerOpt.entries };
    }

    if (baseOpt.type === "item_links" && playerOpt.type === "item_links") {
      return { ...baseOpt, entries: playerOpt.entries };
    }

    if (
      baseOpt.type === "freeform" &&
      playerOpt.type === "freeform" &&
      baseOpt.kind === playerOpt.kind
    ) {
      if (baseOpt.kind === "list" && playerOpt.kind === "list") {
        return { ...baseOpt, items: playerOpt.items };
      }
      if (baseOpt.kind === "dict" && playerOpt.kind === "dict") {
        // Fixed-schema dict (e.g. game_options): the base default owns the keys; only the player's
        // values for matching keys are applied. Renamed/added/removed player keys are ignored, which
        // also self-heals YAMLs corrupted before the keys were locked.
        if (baseOpt.fixedKeys) {
          const playerValueByKey = new Map(playerOpt.entries.map((e) => [e.k, e.v]));
          return {
            ...baseOpt,
            entries: baseOpt.entries.map((e) =>
              playerValueByKey.has(e.k) ? { ...e, v: playerValueByKey.get(e.k) ?? e.v } : e,
            ),
          };
        }
        return { ...baseOpt, entries: playerOpt.entries };
      }
    }

    return baseOpt;
  });

  return { ...base, playerName: player.playerName, options: mergedOptions };
}

// ─── Public API ───────────────────────────────────────────────────────────────

export function parseDefaultYaml(yamlStr: string, optionTypes?: OptionTypesMap | null): ParsedYaml | null {
  try {
    const rawDoc = yaml.load(yamlStr, { schema: yaml.CORE_SCHEMA }) as Record<string, unknown>;
    if (!rawDoc || typeof rawDoc !== "object") return null;

    const playerName = typeof rawDoc["name"] === "string" ? rawDoc["name"] : "Player{number}";
    const playerNameDescription = extractDescription(yamlStr, "name");
    const gameName = Object.keys(rawDoc).find((k) => !STANDARD_KEYS.has(k) && k !== "name");
    if (!gameName) return null;

    const gameBlock = rawDoc[gameName];
    if (!gameBlock || typeof gameBlock !== "object") return null;

    const categories = parseCategories(yamlStr, gameName);

    const gameOptions: GameOption[] = Object.entries(gameBlock as Record<string, unknown>).map(
      ([k, v]) => ({ ...buildOption(k, v, yamlStr, optionTypes), category: categories.get(k) }),
    );
    const gameOptionKeys = new Set(gameOptions.map((o) => o.key));

    // Top-level configurable options not already covered by the game block
    const topLevelOptions: GameOption[] = Object.entries(rawDoc)
      .filter(([k]) => STANDARD_KEYS.has(k) && !METADATA_KEYS.has(k) && !gameOptionKeys.has(k))
      .map(([k, v]) => buildOption(k, v, yamlStr, optionTypes));
    const topLevelOptionKeys = new Set(topLevelOptions.map((o) => o.key));

    return {
      rawDoc,
      playerName,
      playerNameDescription,
      gameName,
      options: [...topLevelOptions, ...gameOptions],
      topLevelOptionKeys,
    };
  } catch {
    return null;
  }
}

export function createRangeEntry(key: string, weight: number, isCustom: boolean): RangeEntry {
  return { id: uid(), key, weight, isCustom };
}

export function addCustomRangeEntry(option: RangeOption, key: string, weight: number): RangeOption {
  if (option.entries.some((e) => e.key === key)) return option;
  return { ...option, entries: [...option.entries, { id: uid(), key, weight, isCustom: true }] };
}

// ─── Serializer ───────────────────────────────────────────────────────────────

export function serializeToYaml(parsed: ParsedYaml): string {
  const doc: Record<string, unknown> = { ...parsed.rawDoc };
  doc["name"] = parsed.playerName;

  const gameBlock: Record<string, unknown> = {};
  for (const opt of parsed.options) {
    const serialized = serializeOption(opt);
    if (parsed.topLevelOptionKeys.has(opt.key)) {
      doc[opt.key] = serialized;
    } else {
      gameBlock[opt.key] = serialized;
    }
  }
  // Remove empty arrays (e.g. plando_items: []) from game block
  for (const k of Object.keys(gameBlock)) {
    if (Array.isArray(gameBlock[k]) && (gameBlock[k] as unknown[]).length === 0) {
      delete gameBlock[k];
    }
  }

  doc[parsed.gameName] = gameBlock;

  return yaml.dump(doc, { lineWidth: -1, noRefs: true, schema: yaml.CORE_SCHEMA });
}

// ─── Validation ───────────────────────────────────────────────────────────────

/**
 * Weighted options (toggle / choice / range) must have at least one value with a
 * weight > 0: a distribution that sums to 0 is impossible for Archipelago to roll
 * and fails generation. Returns the offending options (empty list = all valid).
 */
export function findZeroWeightOptions(options: GameOption[]): { key: string; label: string }[] {
  const offending: { key: string; label: string }[] = [];
  for (const opt of options) {
    let total: number | null = null;
    if (opt.type === "toggle") {
      total = opt.weightFalse + opt.weightTrue;
    } else if (opt.type === "choice") {
      total = opt.choices.reduce((sum, c) => sum + c.weight, 0);
    } else if (opt.type === "range") {
      total = opt.entries.reduce((sum, e) => sum + e.weight, 0);
    }
    if (total !== null && total <= 0) {
      offending.push({ key: opt.key, label: opt.label });
    }
  }
  return offending;
}

export type OutOfBoundsRange = { key: string; label: string; min: number; max: number; values: number[] };

/**
 * Range options whose numeric value(s) fall outside the authoritative `[min, max]` bounds
 * (story 9.25 `optionTypes`). Archipelago rejects such values at generation - block at save.
 * Random aliases (`random`, `random-range-…`) are ignored; only literal numeric values count.
 */
export function findOutOfBoundsRangeOptions(options: GameOption[]): OutOfBoundsRange[] {
  const offending: OutOfBoundsRange[] = [];
  for (const opt of options) {
    if (opt.type !== "range") continue;
    const values = opt.entries
      .filter((e) => e.key.trim() !== "" && !isNaN(Number(e.key)))
      .map((e) => Number(e.key))
      .filter((n) => n < opt.min || n > opt.max);
    if (values.length > 0) {
      offending.push({ key: opt.key, label: opt.label, min: opt.min, max: opt.max, values });
    }
  }
  return offending;
}

function serializeOption(opt: GameOption): unknown {
  if (opt.type === "plando_items") {
    const out = opt.entries
      .filter((e) => e.items.some((i) => i.name.trim() !== ""))
      .map((e) => {
        const itemsDict: Record<string, number> = {};
        for (const i of e.items) {
          if (i.name.trim()) itemsDict[i.name.trim()] = i.quantity;
        }
        const worldValue =
          e.world === "own" ? false :
          e.world === "any" ? true :
          e.world === "random" ? null :
          e.world;
        const forceValue =
          e.force === "true" ? true :
          e.force === "false" ? false :
          "silent";
        const block: Record<string, unknown> = { items: itemsDict };
        const locs = e.locations.map((l) => l.value).filter((v) => v.trim() !== "");
        if (locs.length > 0) block["locations"] = locs;
        block["world"] = worldValue;
        block["from_pool"] = e.fromPool;
        block["force"] = forceValue;
        if (e.percentage !== 100) block["percentage"] = e.percentage;
        return block;
      });
    return out;
  }

  if (opt.type === "item_links") {
    return opt.entries
      .filter((e) => e.name.trim() !== "")
      .map((e) => {
        const block: Record<string, unknown> = { name: e.name.trim() };
        const pool = e.itemPool.filter((i) => i.trim() !== "");
        if (pool.length > 0) block["item_pool"] = pool;
        block["replacement_item"] = e.replacementItem;
        if (e.linkReplacement) block["link_replacement"] = true;
        const local = e.localItems.filter((i) => i.trim() !== "");
        if (local.length > 0) block["local_items"] = local;
        const nonLocal = e.nonLocalItems.filter((i) => i.trim() !== "");
        if (nonLocal.length > 0) block["non_local_items"] = nonLocal;
        return block;
      });
  }

  if (opt.type === "freeform") {
    if (opt.kind === "list") return opt.items.filter((item) => item.trim() !== "");
    const result: Record<string, unknown> = {};
    for (const { k, v } of opt.entries) {
      if (!k.trim()) continue;
      try {
        result[k.trim()] = yaml.load(v.trim(), { schema: yaml.CORE_SCHEMA }) ?? v.trim();
      } catch {
        result[k.trim()] = v.trim();
      }
    }
    return result;
  }

  if (opt.type === "text") return opt.value;

  if (opt.type === "toggle") {
    return { false: opt.weightFalse, true: opt.weightTrue };
  }

  if (opt.type === "choice") {
    if (opt.choices.length === 0) return {};
    const w: Record<string, number> = {};
    for (const c of opt.choices) w[c.value] = c.weight;
    return w;
  }

  // Range - always use dict so re-parsing recovers the range type
  if (opt.entries.length === 0) return opt.min;
  const w: Record<string, number> = {};
  for (const e of opt.entries) w[e.key] = e.weight;
  return w;
}
