import { parseDefaultYaml } from "./archipelago-yaml";
import type { OptionTypesMap } from "./archipelago-yaml";

/**
 * Story 9.33: the apworld's own answer about an option beats the shape of its value.
 *
 * The editor classified every option by looking at the mapping it was given - keys `true`/`false`
 * mean toggle, numeric keys mean range, anything else is a choice. That guess is right most of the
 * time and wrong in two known families:
 *
 *   - a literal dict of settings reads as a weighted choice, and `clampWeight` turns a player name
 *     into the integer 0, which crashes generation (story 4.17, kept as fallback below);
 *   - a `NamedRange` offering a named value beside its numbers fails the "all keys are numeric"
 *     test and falls through to choice, which is why no plain number could be typed into it
 *     (issue #483).
 *
 * Story 9.25 already carried range *bounds* end to end; what was missing was the type itself.
 */

function optionsOf(yaml: string, optionTypes?: OptionTypesMap | null) {
  const parsed = parseDefaultYaml(yaml, optionTypes ?? null);
  if (parsed === null) throw new Error("yaml de test invalide");
  return parsed.options;
}

const NAMED_RANGE_YAML = `name: Alice
game: LEGO Star Wars TCS
LEGO Star Wars TCS:
  minikit_goal_amount:
    0: 0
    360: 50
    Use Percentage Option: 0
`;

describe("authoritative option types", () => {
  /** Issue #483: this is the option a player could only work around with a 1% hack. */
  test("a NamedRange with a named value is a range, not a choice", () => {
    const [option] = optionsOf(NAMED_RANGE_YAML, {
      minikit_goal_amount: { type: "range", min: 0, max: 360, default: 0 },
    });

    expect(option.type).toBe("range");
    if (option.type !== "range") throw new Error("unreachable");
    expect(option.min).toBe(0);
    expect(option.max).toBe(360);
  });

  /** The named value is a legitimate value of the option: losing it would break the round-trip. */
  test("the named value survives beside the numbers", () => {
    const [option] = optionsOf(NAMED_RANGE_YAML, {
      minikit_goal_amount: { type: "range", min: 0, max: 360, default: 0 },
    });

    if (option.type !== "range") throw new Error("unreachable");
    expect(option.entries.map((e) => e.key)).toEqual(expect.arrayContaining(["0", "360", "Use Percentage Option"]));
  });

  /** Without introspection the old guess applies, and this one lands on choice - as it always did. */
  test("the value-shape heuristic remains the fallback", () => {
    const [option] = optionsOf(NAMED_RANGE_YAML, null);

    expect(option.type).toBe("choice");
  });

  test("a declared toggle is a toggle whatever its keys look like", () => {
    const yaml = `name: Alice
game: G
G:
  cheats:
    "true": 0
    "false": 50
`;

    const [option] = optionsOf(yaml, { cheats: { type: "toggle", min: 0, max: 0, default: null } });

    expect(option.type).toBe("toggle");
  });

  /**
   * Story 4.17's guard has to stay reachable: it protects apworlds whose introspection has not been
   * backfilled, and a literal dict misread as a weighted choice crashes generation.
   */
  test("a literal dict without introspection is still a freeform dict", () => {
    const yaml = `name: Alice
game: G
G:
  game_options:
    default_player_name: player_name
    text_speed: fast
`;

    const [option] = optionsOf(yaml, null);

    expect(option.type).toBe("freeform");
    if (option.type !== "freeform" || option.kind !== "dict") throw new Error("unreachable");
    expect(option.entries.map((entry) => entry.v)).toEqual(["player_name", "fast"]);
  });

  /** A pre-9.33 row carries bounds and no type; it must keep behaving as the range it was. */
  test("bounds without a declared type still drive a range", () => {
    const yaml = `name: Alice
game: G
G:
  logic:
    0: 0
    5: 50
`;

    const [option] = optionsOf(yaml, { logic: { type: "range", min: 0, max: 5, default: 1 } });

    expect(option.type).toBe("range");
    if (option.type !== "range") throw new Error("unreachable");
    expect(option.max).toBe(5);
  });
});
