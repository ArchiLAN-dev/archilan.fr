import { isEventRecapIndexPayload } from "./recap-api";

const validEntry = {
  sessionId: "s-1",
  startedAt: "2026-07-12T18:00:00+00:00",
  finishedAt: "2026-07-12T23:00:00+00:00",
  durationSeconds: 18000,
  playerCount: 4,
  winner: { playerName: "Michel_M", game: "Super Mario 64" },
};

describe("isEventRecapIndexPayload", () => {
  it("accepts a list of valid entries, including null winner and null times", () => {
    const payload = {
      data: [validEntry, { ...validEntry, winner: null, startedAt: null, finishedAt: null, durationSeconds: null }],
    };
    expect(isEventRecapIndexPayload(payload)).toBe(true);
  });

  it("accepts an empty list", () => {
    expect(isEventRecapIndexPayload({ data: [] })).toBe(true);
  });

  it("rejects a list containing an invalid entry", () => {
    expect(isEventRecapIndexPayload({ data: [validEntry, { ...validEntry, playerCount: "4" }] })).toBe(false);
    expect(isEventRecapIndexPayload({ data: [{ ...validEntry, winner: { playerName: "X" } }] })).toBe(false);
    expect(isEventRecapIndexPayload({ data: [{ ...validEntry, sessionId: 12 }] })).toBe(false);
  });

  it("rejects malformed payloads", () => {
    expect(isEventRecapIndexPayload(null)).toBe(false);
    expect(isEventRecapIndexPayload({ data: "nope" })).toBe(false);
    expect(isEventRecapIndexPayload([validEntry])).toBe(false);
  });
});
