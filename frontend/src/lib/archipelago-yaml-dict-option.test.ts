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
