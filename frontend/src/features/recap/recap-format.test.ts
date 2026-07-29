import { formatDuration } from "./recap-format";

describe("formatDuration", () => {
  it("formats hours with zero-padded minutes", () => {
    expect(formatDuration(3 * 3600 + 7 * 60 + 12)).toBe("3 h 07 min");
  });

  it("formats minutes with zero-padded seconds below one hour", () => {
    expect(formatDuration(5 * 60 + 3)).toBe("5 min 03 s");
  });

  it("formats bare seconds below one minute", () => {
    expect(formatDuration(42)).toBe("42 s");
  });

  it("formats zero", () => {
    expect(formatDuration(0)).toBe("0 s");
  });

  it("formats exactly one hour", () => {
    expect(formatDuration(3600)).toBe("1 h 00 min");
  });
});
