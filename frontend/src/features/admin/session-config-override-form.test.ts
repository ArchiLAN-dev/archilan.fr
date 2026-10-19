import { initialOverrideValue } from "./session-config-override-form";

/** Story 41.39: ticking « surcharger » starts from the inherited value, not from a fixed default. */
describe("initialOverrideValue", () => {
  const pelleBounties = { key: "pelleBounties", fallback: false };

  it("starts from the value the profile hands down", () => {
    expect(initialOverrideValue(pelleBounties, { pelleBounties: true })).toBe(true);
  });

  it("falls back on the field's default only while the profile is unknown", () => {
    expect(initialOverrideValue(pelleBounties, null)).toBe(false);
    expect(initialOverrideValue({ key: "bronzePerCheck", fallback: 1 }, {})).toBe(1);
  });
});
