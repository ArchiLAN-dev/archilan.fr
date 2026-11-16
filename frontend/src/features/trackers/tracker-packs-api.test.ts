import { getGameTrackerPacks, isGameTrackerPack } from "./tracker-packs-api";

const pack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: null,
  licenseSpdx: "MIT",
  sourceUrl: "https://github.com/o/r",
  isDefault: true,
  hasApVariant: true,
  defaultVariantUid: "itemsonly",
  version: {
    id: "v1",
    packageVersion: null,
    rootPath: "Pack-1.0",
    sizeBytes: 1000,
    sha256: "abc",
    variants: [{ uid: "itemsonly", displayName: "Items only", flags: ["ap"] }],
  },
};

const fetchMock = jest.fn<Promise<Response>, Parameters<typeof fetch>>();

beforeEach(() => {
  fetchMock.mockReset();
  global.fetch = fetchMock;
});

describe("isGameTrackerPack (story 54.2)", () => {
  it("accepts a pack and refuses one without a valid version", () => {
    expect(isGameTrackerPack(pack)).toBe(true);
    expect(isGameTrackerPack({ ...pack, version: { ...pack.version, sha256: 1 } })).toBe(false);
    expect(isGameTrackerPack({ ...pack, isDefault: "yes" })).toBe(false);
  });
});

describe("getGameTrackerPacks", () => {
  it("returns the valid packs of the game", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: [pack, { id: "broken" }] }), { status: 200 }));
    await expect(getGameTrackerPacks("g 1")).resolves.toEqual([pack]);
    expect(String(fetchMock.mock.lastCall?.[0])).toContain("/games/g%201/tracker-packs");
  });

  it("degrades to no tracker when the API fails", async () => {
    fetchMock.mockResolvedValue(new Response("nope", { status: 500 }));
    await expect(getGameTrackerPacks("g1")).resolves.toEqual([]);
    fetchMock.mockRejectedValue(new Error("offline"));
    await expect(getGameTrackerPacks("g1")).resolves.toEqual([]);
  });
});
