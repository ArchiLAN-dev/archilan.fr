import * as yaml from "js-yaml";

import { mergePlayerValues, parseDefaultYaml, serializeToYaml, type ParsedYaml } from "./archipelago-yaml";

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
