import { presettableOptions, startingValue } from "./admin-game-option-presets";

const YAML = `name: Player
game: Hollow Knight
Hollow Knight:
  Goal:
    any: 0
    radiance: 50
  RandomizeGrubs:
    'false': 50
    'true': 0
  GrubHuntGoal:
    46: 50
  StartLocation: king's_pass
`;

describe("story 56.5: the options a preset can set", () => {
  const options = presettableOptions({
    defaultYaml: YAML,
    optionTypes: {
      Goal: { type: "choice", min: 0, max: 0, default: null, values: ["any", "radiance"] },
      RandomizeGrubs: { type: "toggle", min: 0, max: 1, default: null },
      GrubHuntGoal: { type: "range", min: 1, max: 46, default: 46 },
      StartLocation: { type: "text", min: 0, max: 0, default: null },
    },
  });

  it("offers toggles, choices and ranges only", () => {
    expect(options.map((o) => o.key)).toEqual(["Goal", "RandomizeGrubs", "GrubHuntGoal"]);
  });

  it("starts a new value at the default's likeliest roll", () => {
    expect(options.map(startingValue)).toEqual(["radiance", false, 46]);
  });
});
