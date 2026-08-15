import { apiFetch } from "../../lib/apiFetch";
import { fetchSessionRecap, isEventRecapIndexPayload, parseRecapPayload } from "./recap-api";

jest.mock("../../lib/apiFetch");

const mockApiFetch = jest.mocked(apiFetch);

const validEntry = {
  sessionId: "s-1",
  startedAt: "2026-07-12T18:00:00+00:00",
  finishedAt: "2026-07-12T23:00:00+00:00",
  durationSeconds: 18000,
  playerCount: 4,
  winner: { playerName: "Michel_M", game: "Super Mario 64" },
};

const validRecap = {
  sessionId: "s-1",
  eventName: "Ma run privée",
  startedAt: null,
  finishedAt: null,
  durationSeconds: null,
  vodUrl: null,
  generatedAt: "2026-08-15T10:00:00+00:00",
  podium: [],
  graph: { nodes: [], edges: [], localItems: [] },
  superlatives: [],
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

describe("parseRecapPayload", () => {
  it("returns the recap for a valid payload", () => {
    expect(parseRecapPayload({ data: validRecap })).toEqual(validRecap);
  });

  it("returns null for a malformed or missing payload", () => {
    expect(parseRecapPayload({ data: { ...validRecap, podium: "nope" } })).toBeNull();
    expect(parseRecapPayload({ data: { ...validRecap, graph: null } })).toBeNull();
    expect(parseRecapPayload({})).toBeNull();
    expect(parseRecapPayload(null)).toBeNull();
  });
});

describe("fetchSessionRecap", () => {
  afterEach(() => {
    jest.resetAllMocks();
  });

  it("returns the recap when the authenticated request succeeds", async () => {
    mockApiFetch.mockResolvedValue(new Response(JSON.stringify({ data: validRecap }), { status: 200 }));

    await expect(fetchSessionRecap("s-1")).resolves.toEqual(validRecap);
    expect(mockApiFetch).toHaveBeenCalledWith(expect.stringContaining("/parties/s-1/recap"));
  });

  it("returns null on a non-ok response (e.g. 404 for an anonymous/unauthorized viewer)", async () => {
    mockApiFetch.mockResolvedValue(new Response("{}", { status: 404 }));

    await expect(fetchSessionRecap("s-1")).resolves.toBeNull();
  });

  it("returns null when the request throws", async () => {
    mockApiFetch.mockRejectedValue(new Error("network down"));

    await expect(fetchSessionRecap("s-1")).resolves.toBeNull();
  });
});
