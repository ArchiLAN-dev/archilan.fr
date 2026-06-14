import {
  createRangeEntry,
  findOutOfBoundsRangeOptions,
  findZeroWeightOptions,
  type GameOption,
} from "./archipelago-yaml";

function rangeWithKeys(key: string, min: number, max: number, entryKeys: string[]): GameOption {
  return {
    type: "range",
    key,
    label: key,
    min,
    max,
    entries: entryKeys.map((k) => createRangeEntry(k, 50, true)),
  };
}

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

describe("findOutOfBoundsRangeOptions", () => {
  test("flags numeric range values outside [min, max]", () => {
    const out = findOutOfBoundsRangeOptions([
      rangeWithKeys("progression_balancing", 0, 99, ["50", "100"]),
      rangeWithKeys("luigi_max_health", 1, 1000, ["0"]),
    ]);
    expect(out.map((o) => o.key).sort()).toEqual(["luigi_max_health", "progression_balancing"]);
    expect(out.find((o) => o.key === "progression_balancing")?.values).toEqual([100]);
    expect(out.find((o) => o.key === "luigi_max_health")?.values).toEqual([0]);
  });

  test("accepts in-bounds values and ignores random aliases", () => {
    expect(
      findOutOfBoundsRangeOptions([
        rangeWithKeys("progression_balancing", 0, 99, ["0", "50", "99", "random", "random-range-0-99"]),
      ]),
    ).toEqual([]);
  });

  test("ignores non-range options", () => {
    expect(
      findOutOfBoundsRangeOptions([
        { type: "toggle", key: "death_link", label: "death_link", weightFalse: 50, weightTrue: 0 },
      ]),
    ).toEqual([]);
  });
});
