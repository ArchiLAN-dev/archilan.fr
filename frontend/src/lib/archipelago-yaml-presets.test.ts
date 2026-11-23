import { applyOptionPreset, asOptionPresets, parseDefaultYaml, serializeToYaml, type OptionTypesMap } from "./archipelago-yaml";

/** Story 56.5: a preset sets option values (never the text of the file) as a certain roll. */

const YAML = `name: Player
game: Hollow Knight
Hollow Knight:
  Goal:
    any: 50
    hollowknight: 0
    radiance: 0
  RandomizeGrubs:
    'false': 0
    'true': 50
  GrubHuntGoal:
    46: 50
    random: 0
`;

const TYPES: OptionTypesMap = {
  Goal: { type: "choice", min: 0, max: 0, default: null, values: ["any", "hollowknight", "radiance"] },
  RandomizeGrubs: { type: "toggle", min: 0, max: 1, default: null },
  GrubHuntGoal: { type: "range", min: 1, max: 46, default: 46 },
};

function parsed() {
  const result = parseDefaultYaml(YAML, TYPES);
  if (result === null) throw new Error("the fixture does not parse");
  return result;
}

describe("story 56.5: option presets", () => {
  it("puts all the weight on the preset's values and keeps the other options", () => {
    const { parsed: next, applied } = applyOptionPreset(parsed(), {
      name: "Court",
      values: { Goal: "hollowknight", RandomizeGrubs: false, GrubHuntGoal: 10 },
    });

    expect(applied).toHaveLength(3);
    const byKey = new Map(next.options.map((o) => [o.key, o]));
    const goal = byKey.get("Goal");
    expect(goal?.type === "choice" && goal.choices.find((c) => c.weight > 0)?.value).toBe("hollowknight");
    const grubs = byKey.get("RandomizeGrubs");
    expect(grubs?.type === "toggle" && [grubs.weightTrue, grubs.weightFalse]).toEqual([0, 50]);
    const hunt = byKey.get("GrubHuntGoal");
    expect(hunt?.type === "range" && hunt.entries.filter((e) => e.weight > 0).map((e) => e.key)).toEqual(["10"]);

    const yaml = serializeToYaml(next);
    expect(yaml).toContain("hollowknight: 50");
    expect(yaml).toContain("'10': 50");
  });

  it("leaves out a value the option does not offer", () => {
    const { applied } = applyOptionPreset(parsed(), { name: "X", values: { Goal: "nope", GrubHuntGoal: 99, Unknown: true } });

    expect(applied).toEqual([]);
  });

  it("reads only well-formed presets from the API", () => {
    expect(asOptionPresets([{ name: "Court", values: { Goal: "any" } }, { name: 3 }, { name: "Bad", values: [1] }, "x"])).toEqual([
      { name: "Court", values: { Goal: "any" } },
    ]);
    expect(asOptionPresets(null)).toEqual([]);
  });
});
