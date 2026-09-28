import { canSubmitSanction, SANCTION_OPTIONS, sanctionOption, sanctionUntil } from "./sanction-rules";

/**
 * Story 39.11. The sanction window is the only place a sanction is typed in; what it lets through
 * mirrors what the server requires (a reason, and an end date for a suspension).
 */
describe("sanction rules", () => {
  test("every action needs a reason, blank does not count", () => {
    for (const option of SANCTION_OPTIONS) {
      expect(canSubmitSanction({ command: option.command, reason: "  ", until: "2026-10-01T12:00" })).toBe(false);
    }
    expect(canSubmitSanction({ command: "warn", reason: "insultes", until: "" })).toBe(true);
    expect(canSubmitSanction({ command: "lift", reason: "erreur de ma part", until: "" })).toBe(true);
  });

  test("a suspension also needs its end date", () => {
    expect(canSubmitSanction({ command: "suspend", reason: "calme-toi", until: "" })).toBe(false);
    expect(canSubmitSanction({ command: "suspend", reason: "calme-toi", until: "2026-10-01T12:00" })).toBe(true);
  });

  test("the end date travels as an instant, and only for a suspension", () => {
    expect(sanctionUntil("suspend", "2026-10-01T12:00")).toBe(new Date("2026-10-01T12:00").toISOString());
    expect(sanctionUntil("ban", "2026-10-01T12:00")).toBeUndefined();
    expect(sanctionUntil("suspend", "")).toBeUndefined();
  });

  test("only the ban is dangerous, and each action names its own button", () => {
    expect(SANCTION_OPTIONS.filter((option) => option.danger).map((option) => option.command)).toEqual(["ban"]);
    expect(sanctionOption("ban").submitLabel).toBe("Bannir");
    expect(sanctionOption("note").submitLabel).toBe("Enregistrer la note");
    expect(sanctionOption("lift").submitLabel).toBe("Lever la sanction");
  });
});
