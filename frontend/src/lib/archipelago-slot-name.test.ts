import { isValidSlotName, SLOT_NAME_MAX_LENGTH } from "./archipelago-yaml";

describe("isValidSlotName", () => {
  test("accepts letters, digits and underscore", () => {
    expect(isValidSlotName("Alice")).toBe(true);
    expect(isValidSlotName("Bob_123")).toBe(true);
  });

  test("accepts Archipelago placeholders {number}/{player}", () => {
    expect(isValidSlotName("Player{number}")).toBe(true);
    expect(isValidSlotName("{player}")).toBe(true);
    expect(isValidSlotName("p{NUMBER}")).toBe(true);
  });

  test("rejects special characters", () => {
    expect(isValidSlotName("O'Brien")).toBe(false);
    expect(isValidSlotName("Émilie")).toBe(false);
    expect(isValidSlotName("a b")).toBe(false);
    expect(isValidSlotName("a-b")).toBe(false);
    expect(isValidSlotName("Player{rng}")).toBe(false);
  });

  test("rejects empty and over-length names", () => {
    expect(isValidSlotName("")).toBe(false);
    expect(isValidSlotName("a".repeat(SLOT_NAME_MAX_LENGTH + 1))).toBe(false);
    expect(isValidSlotName("a".repeat(SLOT_NAME_MAX_LENGTH))).toBe(true);
  });
});
