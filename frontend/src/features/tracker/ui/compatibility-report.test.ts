import { compatibilityReport, MAX_MESSAGE_LENGTH } from "./compatibility-report";

describe("compatibilityReport", () => {
  it("cuts the pack's messages before they leave the browser (story 54.13)", () => {
    const long = "x".repeat(5_000);

    const failed = compatibilityReport("standard", { kind: "failed", message: long }, []);
    expect(failed?.errors[0]?.message).toHaveLength(MAX_MESSAGE_LENGTH);

    const ready = { kind: "ready", pack: {}, images: {} } as unknown as Parameters<typeof compatibilityReport>[1];
    const report = compatibilityReport("standard", ready, [{ level: "error", message: long }]);
    expect(report?.errors[0]?.message).toHaveLength(MAX_MESSAGE_LENGTH);
    expect(report?.errors[0]?.message.endsWith("…")).toBe(true);
  });
});
