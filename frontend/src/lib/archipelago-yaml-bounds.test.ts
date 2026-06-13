import {
  asOptionTypesMap,
  parseDefaultYaml,
  type OptionTypesMap,
  type RangeOption,
} from "./archipelago-yaml";

function rangeOption(parsedKey: string, yaml: string, optionTypes?: OptionTypesMap | null): RangeOption {
  const parsed = parseDefaultYaml(yaml, optionTypes);
  if (!parsed) throw new Error("parse failed");
  const opt = parsed.options.find((o) => o.key === parsedKey);
  if (!opt || opt.type !== "range") throw new Error(`expected range option ${parsedKey}, got ${opt?.type}`);
  return opt;
}

const MD_YAML = `name: t
game: Muse Dash
Muse Dash:
  song_difficulty_min:
    4: 50
`;

describe("range bounds from introspected optionTypes (story 9.25)", () => {
  test("introspected min/max are used for a dict range", () => {
    const opt = rangeOption("song_difficulty_min", MD_YAML, {
      song_difficulty_min: { min: 1, max: 11, default: 4 },
    });
    expect(opt.min).toBe(1);
    expect(opt.max).toBe(11);
  });

  test("a scalar option known to introspection becomes a range (replaces hardcoded list)", () => {
    const yaml = `name: t
game: G
progression_balancing: 50
G:
  foo: 1
`;
    const opt = rangeOption("progression_balancing", yaml, {
      progression_balancing: { min: 0, max: 99, default: 50 },
    });
    expect(opt.min).toBe(0);
    expect(opt.max).toBe(99);
  });

  test("without optionTypes, bounds fall back to the {0,100} default when no comments", () => {
    const opt = rangeOption("song_difficulty_min", MD_YAML);
    expect(opt.min).toBe(0);
    expect(opt.max).toBe(100);
  });
});

describe("asOptionTypesMap", () => {
  test("accepts a valid map and drops malformed entries", () => {
    expect(
      asOptionTypesMap({
        a: { min: 1, max: 11, default: 4 },
        b: { min: 3, max: 10 },
        bad: { min: "x", max: 5 },
        nope: 7,
      }),
    ).toEqual({
      a: { min: 1, max: 11, default: 4 },
      b: { min: 3, max: 10, default: null },
    });
  });

  test("returns null for non-objects or empty", () => {
    expect(asOptionTypesMap(null)).toBeNull();
    expect(asOptionTypesMap({})).toBeNull();
    expect(asOptionTypesMap("x")).toBeNull();
  });
});
