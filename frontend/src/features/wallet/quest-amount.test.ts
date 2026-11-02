import { formatHours, formatQuestAmount } from "./quest-amount";

describe("quest amounts (story 41.64)", () => {
  test("minutes read as hours", () => {
    expect(formatHours(360)).toBe("6 h");
    expect(formatHours(150)).toBe("2 h 30");
    expect(formatHours(65)).toBe("1 h 05");
    expect(formatHours(45)).toBe("45 min");
    expect(formatHours(-3)).toBe("0 min");
  });

  test("only a duration is turned into hours", () => {
    expect(formatQuestAmount(120, true)).toBe("2 h");
    expect(formatQuestAmount(120, false)).toBe("120");
  });
});
