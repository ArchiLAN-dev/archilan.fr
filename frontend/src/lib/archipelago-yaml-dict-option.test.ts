import * as yaml from "js-yaml";

import { asOptionTypesMap, mergePlayerValues, parseDefaultYaml, serializeToYaml, type OptionTypesMap, type ParsedYaml } from "./archipelago-yaml";

function parse(input: string): ParsedYaml {
  const parsed = parseDefaultYaml(input);
  if (!parsed) throw new Error("parse failed");
  return parsed;
}

// `game_options` is a literal dict of named sub-settings (Pokemon Platinum), NOT a weighted
// distribution. The editor used to fall through to the `choice` branch and run every value
// through `clampWeight`, coercing `default_player_name: player_name` to `0` (an int) - which
// later crashed generation with "TypeError: 'int' object is not iterable".
const YAML_WITH_GAME_OPTIONS = `name: Player{number}
game: Pokemon Platinum
Pokemon Platinum:
  game_options:
    battle_scene: 'on'
    battle_style: shift
    default_player_name: player_name
    text_frame: 1
    text_speed: mid
  goal:
    champion: 50
  hms:
    'false': 0
    'true': 50
`;

describe("buildOption - literal dict options (game_options)", () => {
  test("a dict with non-numeric values parses as a freeform dict, not a weighted choice", () => {
    const opt = parse(YAML_WITH_GAME_OPTIONS).options.find((o) => o.key === "game_options");
    if (opt?.type !== "freeform" || opt.kind !== "dict") {
      throw new Error(`expected freeform dict, got ${opt?.type}`);
    }
    const byKey = new Map(opt.entries.map((e) => [e.k, e.v]));
    expect(byKey.get("default_player_name")).toBe("player_name");
    expect(byKey.get("text_frame")).toBe("1");
  });

  test("round-trip preserves literal string values (no coercion to int weights)", () => {
    const out = serializeToYaml(parse(YAML_WITH_GAME_OPTIONS));
    const reparsed = yaml.load(out, { schema: yaml.CORE_SCHEMA }) as Record<string, unknown>;
    const game = reparsed["Pokemon Platinum"] as Record<string, unknown>;
    const gameOptions = game["game_options"] as Record<string, unknown>;

    expect(gameOptions["default_player_name"]).toBe("player_name");
    expect(typeof gameOptions["default_player_name"]).toBe("string");
    // numeric sub-values still round-trip as numbers
    expect(gameOptions["text_frame"]).toBe(1);
  });

  test("genuine weighted options are still classified correctly", () => {
    const options = parse(YAML_WITH_GAME_OPTIONS).options;
    expect(options.find((o) => o.key === "goal")?.type).toBe("choice");
    expect(options.find((o) => o.key === "hms")?.type).toBe("toggle");
  });
});

// Slay the Spire's `advanced_characters` maps a character name to a block of its own settings.
// The editor used to flatten each sub-block with `String(...)`, saving the literal
// "[object Object]" - which js-yaml then re-read as the list `['object Object']`, and Archipelago
// rejected at generation with "schema.SchemaError: Key 'ironclad' error:".
const YAML_WITH_NESTED_DICT = `name: Player{number}
game: Slay the Spire
Slay the Spire:
  advanced_characters:
    ironclad:
      ascension: 1
      ascension_down: 0
      downfall: 0
      final_act: 1
      key_sanity: 0
  use_advanced_characters:
    'false': 50
    'true': 0
`;

describe("buildOption - dict options with nested block values", () => {
  test("a nested sub-block is kept as editable YAML flow, never \"[object Object]\"", () => {
    const opt = parse(YAML_WITH_NESTED_DICT).options.find((o) => o.key === "advanced_characters");
    if (opt?.type !== "freeform" || opt.kind !== "dict") {
      throw new Error(`expected freeform dict, got ${opt?.type}`);
    }
    const ironclad = opt.entries.find((e) => e.k === "ironclad")?.v ?? "";
    expect(ironclad).not.toContain("[object Object]");
    expect(yaml.load(ironclad, { schema: yaml.CORE_SCHEMA })).toEqual({
      ascension: 1, ascension_down: 0, downfall: 0, final_act: 1, key_sanity: 0,
    });
  });

  test("round-trip preserves the nested block as a mapping", () => {
    const out = serializeToYaml(parse(YAML_WITH_NESTED_DICT));
    const reparsed = yaml.load(out, { schema: yaml.CORE_SCHEMA }) as Record<string, unknown>;
    const game = reparsed["Slay the Spire"] as Record<string, unknown>;
    const advanced = game["advanced_characters"] as Record<string, unknown>;

    expect(advanced["ironclad"]).toEqual({
      ascension: 1, ascension_down: 0, downfall: 0, final_act: 1, key_sanity: 0,
    });
  });

  test("an empty nested dict round-trips as an empty mapping", () => {
    const out = serializeToYaml(parse(`name: t
game: Slay the Spire
Slay the Spire:
  advanced_characters: {}
`));
    const reparsed = yaml.load(out, { schema: yaml.CORE_SCHEMA }) as Record<string, unknown>;
    const game = reparsed["Slay the Spire"] as Record<string, unknown>;

    expect(game["advanced_characters"]).toEqual({});
  });
});

describe("buildOption - list options with nested item values", () => {
  test("a list of blocks round-trips as mappings, and plain names stay strings", () => {
    const out = serializeToYaml(parse(`name: t
game: G
G:
  blocks:
    - {name: a, weight: 1}
  exclude_locations:
    - '12'
    - Floor 3
`));
    const reparsed = yaml.load(out, { schema: yaml.CORE_SCHEMA }) as Record<string, unknown>;
    const game = reparsed["G"] as Record<string, unknown>;

    expect(game["blocks"]).toEqual([{ name: "a", weight: 1 }]);
    // A location name that looks numeric must not be read back as a number.
    expect(game["exclude_locations"]).toEqual(["12", "Floor 3"]);
  });
});

describe("buildOption - fixed-schema dict key locking", () => {
  test("a literal dict is flagged fixedKeys (keys locked in the editor)", () => {
    const opt = parse(YAML_WITH_GAME_OPTIONS).options.find((o) => o.key === "game_options");
    if (opt?.type !== "freeform" || opt.kind !== "dict") {
      throw new Error(`expected freeform dict, got ${opt?.type}`);
    }
    expect(opt.fixedKeys).toBe(true);
  });

  test("a player-composed dict (start_inventory) stays editable (no fixedKeys)", () => {
    const opt = parse(`name: t
game: G
G:
  start_inventory:
    Bomb: 1
`).options.find((o) => o.key === "start_inventory");
    if (opt?.type !== "freeform" || opt.kind !== "dict") {
      throw new Error(`expected freeform dict, got ${opt?.type}`);
    }
    expect(opt.fixedKeys).toBeFalsy();
  });

  test("merge of a fixedKeys dict keeps base keys and applies only matching player values", () => {
    // The player's saved YAML renamed a key, dropped one, and added a junk key. None of that must
    // leak through: the fixed schema comes from the base default, only matching values are applied.
    const player = `name: t
game: Pokemon Platinum
Pokemon Platinum:
  game_options:
    default_player_name: Sacha
    junk_key: nope
    text_speed: fast
`;
    const merged = mergePlayerValues(parse(YAML_WITH_GAME_OPTIONS), parse(player));
    const opt = merged.options.find((o) => o.key === "game_options");
    if (opt?.type !== "freeform" || opt.kind !== "dict") {
      throw new Error(`expected freeform dict, got ${opt?.type}`);
    }
    const byKey = new Map(opt.entries.map((e) => [e.k, e.v]));
    // base keys preserved exactly (no junk_key, none dropped)
    expect([...byKey.keys()].sort()).toEqual(
      ["battle_scene", "battle_style", "default_player_name", "text_frame", "text_speed"].sort(),
    );
    expect(byKey.has("junk_key")).toBe(false);
    // player values applied for matching keys
    expect(byKey.get("default_player_name")).toBe("Sacha");
    expect(byKey.get("text_speed")).toBe("fast");
    // untouched key keeps the base default
    expect(byKey.get("battle_style")).toBe("shift");
  });
});

// ─── Story 9.51: declared sub-option vocabularies ─────────────────────────────
//
// An OptionDict declares no value vocabulary of its own; only a world that sets a `schema` on it
// says what each sub-setting accepts. When it does, that reaches the editor as `keys` - which is
// NOT the same field as a dict's `values` (those are the sub-setting *names*, `validKeys`).

const WITH_SCHEMA: OptionTypesMap = {
  game_options: {
    type: "dict",
    min: 0,
    max: 0,
    default: null,
    values: ["battle_scene", "battle_style", "default_player_name", "text_frame", "text_speed"],
    keys: {
      battle_style: { values: ["shift", "set"] },
      text_speed: { values: ["mid", "slow", "fast"] },
    },
  },
};

function dictOption(yamlStr: string, optionTypes?: OptionTypesMap) {
  const parsed = parseDefaultYaml(yamlStr, optionTypes);
  const opt = parsed?.options.find((o) => o.key === "game_options");
  if (opt?.type !== "freeform" || opt.kind !== "dict") throw new Error("expected a freeform dict");
  return opt;
}

describe("asOptionTypesMap - dict sub-option values", () => {
  test("a declared vocabulary survives the boundary", () => {
    const map = asOptionTypesMap({
      game_options: { type: "dict", keys: { battle_style: { values: ["shift", "set"] } } },
    });
    expect(map?.game_options.keys).toEqual({ battle_style: { values: ["shift", "set"] } });
  });

  test("a sub-setting left with fewer than two values is dropped, not kept half-complete", () => {
    // Half a vocabulary is the worst case for a dropdown: authoritative-looking and incomplete.
    const map = asOptionTypesMap({
      game_options: {
        type: "dict",
        keys: {
          single: { values: ["only"] },
          empty: { values: [] },
          duplicated: { values: ["a", "a"] },
          mixed: { values: ["a", 2, null] },
          malformed: { values: "not-a-list" },
          missing: {},
        },
      },
    });
    expect(map?.game_options.keys).toBeUndefined();
  });

  test("non-strings are dropped and duplicates collapsed, in order", () => {
    const map = asOptionTypesMap({
      game_options: { type: "dict", keys: { k: { values: ["b", 1, "a", "b", ""] } } },
    });
    expect(map?.game_options.keys?.k.values).toEqual(["b", "a"]);
  });

  test("a payload with no keys at all reads exactly as before", () => {
    const map = asOptionTypesMap({ game_options: { type: "dict", values: ["battle_style"] } });
    expect(map?.game_options.keys).toBeUndefined();
    expect(map?.game_options.values).toEqual(["battle_style"]);
  });
});

describe("buildOption - dict sub-option choices", () => {
  test("only the sub-settings the apworld spoke about get a vocabulary", () => {
    const opt = dictOption(YAML_WITH_GAME_OPTIONS, WITH_SCHEMA);
    expect(opt.entryChoices).toEqual({
      battle_style: ["shift", "set"],
      text_speed: ["mid", "slow", "fast"],
    });
    // The keys the schema said nothing about keep their free text field.
    expect(opt.entryChoices?.["default_player_name"]).toBeUndefined();
  });

  test("a world that declares nothing renders exactly as it did before", () => {
    const opt = dictOption(YAML_WITH_GAME_OPTIONS, {
      game_options: { type: "dict", min: 0, max: 0, default: null },
    });
    expect(opt.entryChoices).toBeUndefined();
    expect(opt.fixedKeys).toBe(true);
    expect(opt.entries.map((e) => e.k)).toContain("default_player_name");
  });

  test("the round-trip is untouched by the vocabularies", () => {
    // A declared list changes what the editor offers, never what it writes.
    const withSchema = serializeToYaml(
      (() => {
        const p = parseDefaultYaml(YAML_WITH_GAME_OPTIONS, WITH_SCHEMA);
        if (!p) throw new Error("parse failed");
        return p;
      })(),
    );
    expect(withSchema).toBe(serializeToYaml(parse(YAML_WITH_GAME_OPTIONS)));
  });

  test("merging a player's values keeps the vocabularies", () => {
    const base = parseDefaultYaml(YAML_WITH_GAME_OPTIONS, WITH_SCHEMA);
    const player = parseDefaultYaml(YAML_WITH_GAME_OPTIONS.replace("battle_style: shift", "battle_style: set"), WITH_SCHEMA);
    if (!base || !player) throw new Error("parse failed");

    const merged = mergePlayerValues(base, player).options.find((o) => o.key === "game_options");
    if (merged?.type !== "freeform" || merged.kind !== "dict") throw new Error("expected a dict");

    expect(merged.entryChoices?.["battle_style"]).toEqual(["shift", "set"]);
    expect(new Map(merged.entries.map((e) => [e.k, e.v])).get("battle_style")).toBe("set");
  });
});
