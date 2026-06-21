import { mergePlayerValues, parseDefaultYaml, type RangeOption } from "./archipelago-yaml";

function parse(yaml: string) {
  const parsed = parseDefaultYaml(yaml);
  if (!parsed) throw new Error("parse failed");
  return parsed;
}

function rangeEntries(parsedKey: string, base: string, player: string): RangeOption["entries"] {
  const merged = mergePlayerValues(parse(base), parse(player));
  const opt = merged.options.find((o) => o.key === parsedKey);
  if (!opt || opt.type !== "range") throw new Error(`expected range option ${parsedKey}, got ${opt?.type}`);
  return opt.entries;
}

// Default exposes two numeric values; the saved override is what the admin actually kept.
const DEFAULT_YAML = `name: t
game: G
G:
  my_range:
    1: 50
    2: 50
`;

describe("mergePlayerValues - weighted range, reload after save (bug #4)", () => {
  test("a default value removed in the saved override does not reappear", () => {
    // Admin deleted value 2: the saved YAML only carries value 1.
    const player = `name: t
game: G
G:
  my_range:
    1: 50
`;
    const entries = rangeEntries("my_range", DEFAULT_YAML, player);
    const keys = entries.map((e) => e.key);
    expect(keys).toContain("1");
    expect(keys).not.toContain("2"); // would previously come back as a 0% entry
  });

  test("a custom value added by the admin is preserved on reload", () => {
    // Admin removed 2 and added a custom value 3.
    const player = `name: t
game: G
G:
  my_range:
    1: 50
    3: 50
`;
    const entries = rangeEntries("my_range", DEFAULT_YAML, player);
    const byKey = new Map(entries.map((e) => [e.key, e]));
    expect(byKey.get("3")?.weight).toBe(50); // would previously be filtered out
    expect(byKey.get("3")?.isCustom).toBe(true);
    expect(byKey.has("2")).toBe(false);
  });

  test("kept default values retain their weight and default metadata", () => {
    const player = `name: t
game: G
G:
  my_range:
    1: 70
    2: 30
`;
    const entries = rangeEntries("my_range", DEFAULT_YAML, player);
    const byKey = new Map(entries.map((e) => [e.key, e]));
    expect(byKey.get("1")?.weight).toBe(70);
    expect(byKey.get("2")?.weight).toBe(30);
    expect(byKey.get("1")?.isCustom).toBe(false);
  });
});
