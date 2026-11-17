import { ARCHIVE_CACHE_LIMIT, fetchTrackerArchive, getGameTrackerPacks, isGameTrackerPack } from "./tracker-packs-api";

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

/** Cache Storage as the browser has it, in memory: keys in insertion order. */
function memoryCaches() {
  const entries = new Map<string, Response>();
  const cache = {
    match: async (url: string) => entries.get(url)?.clone(),
    put: async (url: string, response: Response) => void entries.set(url, response),
    delete: async (key: string | Request) => entries.delete(typeof key === "string" ? key : key.url),
    keys: async () => [...entries.keys()].map((url) => new Request(url)),
  };
  Object.defineProperty(globalThis, "caches", { value: { open: async () => cache }, configurable: true });
  return entries;
}

describe("fetchTrackerArchive (story 54.5)", () => {
  afterEach(() => {
    Reflect.deleteProperty(globalThis, "caches");
  });

  it("downloads the zip with its progress, then serves it from the browser cache", async () => {
    const entries = memoryCaches();
    fetchMock.mockResolvedValueOnce(new Response(new Uint8Array([1, 2, 3, 4]), { headers: { "Content-Length": "4" } }));
    const progress: [number, number | null][] = [];

    const first = await fetchTrackerArchive("v1", (loaded, total) => progress.push([loaded, total]));
    expect(first === null ? null : [...new Uint8Array(first)]).toEqual([1, 2, 3, 4]);
    expect(progress.at(-1)).toEqual([4, 4]);
    expect(String(fetchMock.mock.calls[0]?.[0])).toMatch(/\/tracker-pack-versions\/v1\/archive$/);
    expect(entries.size).toBe(1);

    const second = await fetchTrackerArchive("v1", () => undefined);
    expect(second === null ? null : [...new Uint8Array(second)]).toEqual([1, 2, 3, 4]);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("keeps only the most recently opened versions", async () => {
    const entries = memoryCaches();
    for (let index = 0; index <= ARCHIVE_CACHE_LIMIT; index += 1) {
      fetchMock.mockResolvedValueOnce(new Response(new Uint8Array([index])));
      await fetchTrackerArchive(`v${index}`, () => undefined);
    }
    expect(entries.size).toBe(ARCHIVE_CACHE_LIMIT);
    expect([...entries.keys()].some((url) => url.includes("/v0/"))).toBe(false);
  });

  it("returns null when the archive cannot be had, cache or not", async () => {
    fetchMock.mockResolvedValueOnce(new Response("", { status: 404 }));
    expect(await fetchTrackerArchive("v1", () => undefined)).toBeNull();
    fetchMock.mockRejectedValueOnce(new Error("offline"));
    expect(await fetchTrackerArchive("v1", () => undefined)).toBeNull();
  });
});
