import {
  createRangeEntry,
  findZeroWeightOptions,
  type GameOption,
} from "./archipelago-yaml";

function toggle(key: string, weightFalse: number, weightTrue: number): GameOption {
  return { type: "toggle", key, label: key, weightFalse, weightTrue };
}

function choice(key: string, weights: number[]): GameOption {
  return {
    type: "choice",
    key,
    label: key,
    choices: weights.map((w, i) => ({ value: `c${i}`, weight: w })),
  };
}

function range(key: string, weights: number[]): GameOption {
  return {
    type: "range",
    key,
    label: key,
    min: 1,
    max: 11,
    entries: weights.map((w, i) => createRangeEntry(String(i + 1), w, false)),
  };
}

describe("findZeroWeightOptions", () => {
  test("flags weighted options whose weights all sum to 0", () => {
    const offending = findZeroWeightOptions([
      toggle("dead_link", 0, 0),
      choice("grade_needed", [0, 0, 0]),
      range("song_difficulty_min", [0, 0, 0]),
    ]);
    expect(offending.map((o) => o.key).sort()).toEqual([
      "dead_link",
      "grade_needed",
      "song_difficulty_min",
    ]);
  });

  test("accepts options with at least one weight > 0", () => {
    expect(
      findZeroWeightOptions([
        toggle("dead_link", 0, 50),
        choice("grade_needed", [50, 0, 0]),
        range("song_difficulty_min", [0, 0, 50, 0]),
      ]),
    ).toEqual([]);
  });

  test("ignores non-weighted options (text/freeform stay valid even with 0s)", () => {
    const offending = findZeroWeightOptions([
      { type: "text", key: "item_links", label: "item_links", value: "0" },
    ]);
    expect(offending).toEqual([]);
  });
});
