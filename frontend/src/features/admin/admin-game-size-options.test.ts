import { orderedSelection, rangeOptions } from "./admin-game-size-options";

const YAML = `name: Player
game: Hollow Knight
Hollow Knight:
  Goal:
    any: 50
  GrubHuntGoal:
    46: 50
  Charms:
    20: 50
`;

describe("story 56.7: size options", () => {
  const options = rangeOptions({
    defaultYaml: YAML,
    optionTypes: {
      Goal: { type: "choice", min: 0, max: 0, default: null, values: ["any"] },
      GrubHuntGoal: { type: "range", min: 1, max: 46, default: 46 },
      Charms: { type: "range", min: 0, max: 40, default: 20 },
    },
  });

  it("offers the numeric options only", () => {
    expect(options.map((o) => o.key)).toEqual(["GrubHuntGoal", "Charms"]);
  });

  it("saves the ticked ones in the order of the list", () => {
    expect(orderedSelection(options, new Set(["Charms", "GrubHuntGoal", "Goal"]))).toEqual(["GrubHuntGoal", "Charms"]);
  });
});
