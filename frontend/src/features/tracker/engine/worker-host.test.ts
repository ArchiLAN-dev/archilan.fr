import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

import { strToU8, zipSync } from "fflate";

import type { TrackerResponse } from "./protocol";
import { createWorkerHost, FRAME_INTERVAL_MS } from "./worker-host";

const MINI_PACK = join(__dirname, "__fixtures__", "mini-pack");

/** The mini pack zipped as GitHub does: everything under one root folder. */
function miniPackZip(): ArrayBuffer {
  const entries: Record<string, Uint8Array> = {};
  const walk = (dir: string): void => {
    for (const name of readdirSync(dir)) {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) walk(path);
      else entries[`mini-pack-1.0.0/${relative(MINI_PACK, path).replaceAll("\\", "/")}`] = new Uint8Array(readFileSync(path));
    }
  };
  walk(MINI_PACK);
  const zip = zipSync(entries);
  return zip.buffer.slice(zip.byteOffset, zip.byteOffset + zip.byteLength) as ArrayBuffer;
}

function host() {
  const responses: TrackerResponse[] = [];
  const worker = createWorkerHost((response) => responses.push(response));
  const answer = (requestId: number) => responses.find((response) => "requestId" in response && response.requestId === requestId);
  return { worker, responses, answer };
}

describe("the tracker Worker host", () => {
  it("answers the page's heartbeat, pack loaded or not (story 54.13)", async () => {
    const { worker, answer } = host();
    await worker.handle({ type: "ping", requestId: 7 });
    expect(answer(7)).toEqual({ type: "pong", requestId: 7 });
  });

  it("loads a zipped pack, applies actions and answers each request by its id", async () => {
    const { worker, answer } = host();
    await worker.handle({ type: "load", requestId: 1, archive: miniPackZip(), rootPath: "mini-pack-1.0.0", variantUid: "standard" });

    const loaded = answer(1);
    if (loaded?.type !== "loaded") throw new Error(`not loaded: ${JSON.stringify(loaded)}`);
    expect(loaded.pack.manifest.name).toBe("Mini pack");
    const sword = loaded.pack.items.find((item) => item.name === "Sword");
    if (sword === undefined) throw new Error("no sword");
    expect(loaded.state.items[sword.id]).toMatchObject({ active: false });

    await worker.handle({ type: "itemClick", requestId: 2, itemId: sword.id, button: "left" });
    const state = answer(2);
    expect(state?.type).toBe("state");
    if (state?.type === "state") expect(state.state.items[sword.id]).toMatchObject({ active: true });

    await worker.handle({ type: "bulk", requestId: 3, actions: [{ type: "itemClick", itemId: sword.id, button: "left" }] });
    expect(answer(3)?.type).toBe("state");
  });

  it("serves pack images as typed blobs", async () => {
    const { worker, answer } = host();
    await worker.handle({ type: "load", requestId: 1, archive: miniPackZip(), rootPath: "mini-pack-1.0.0", variantUid: "standard" });
    await worker.handle({ type: "image", requestId: 2, path: "images/sword.png" });
    await worker.handle({ type: "image", requestId: 3, path: "images/none.png" });

    const image = answer(2);
    if (image?.type !== "image" || image.blob === null) throw new Error("no image");
    expect(image.blob.type).toBe("image/png");
    expect(image.blob.size).toBe(8);
    expect(answer(3)).toMatchObject({ type: "image", blob: null });
  });

  it("answers with an error for a bad message, an action before load, an unknown item or a broken pack", async () => {
    const { worker, answer, responses } = host();
    await worker.handle({ type: "itemClick", requestId: 1, itemId: 1, button: "middle" });
    expect(answer(1)).toMatchObject({ type: "error", kind: "bad_request" });

    await worker.handle({ type: "itemClick", requestId: 2, itemId: 1, button: "left" });
    expect(answer(2)).toMatchObject({ type: "error", kind: "not_loaded" });

    await worker.handle({ type: "load", requestId: 3, archive: new ArrayBuffer(4), rootPath: "", variantUid: "standard" });
    expect(answer(3)).toMatchObject({ type: "error", kind: "archive_invalid" });

    await worker.handle({ type: "load", requestId: 4, archive: miniPackZip(), rootPath: "mini-pack-1.0.0", variantUid: "standard" });
    await worker.handle({ type: "itemClick", requestId: 5, itemId: 999, button: "left" });
    expect(answer(5)).toMatchObject({ type: "error", kind: "bad_request" });

    expect(responses.some((response) => response.type === "log")).toBe(true);
  });
});

describe("the Worker's clock for frame handlers (story 54.11)", () => {
  afterEach(() => jest.useRealTimers());

  it("runs while the pack has frame handlers and sends what they change", async () => {
    jest.useFakeTimers();
    const files: Record<string, Uint8Array> = {
      "p/manifest.json": strToU8(JSON.stringify({ name: "p", package_uid: "p", variants: { standard: {} } })),
      "p/items.json": strToU8(JSON.stringify([{ name: "Lamp", type: "toggle", codes: "lamp" }])),
      "p/scripts/init.lua": strToU8(`Tracker:AddItems("items.json")
ScriptHost:AddOnFrameHandler("once", function()
  Tracker:FindObjectForCode("lamp").Active = true
  ScriptHost:RemoveOnFrameHandler("once")
end)`),
    };
    const zip = zipSync(files);
    const { worker, responses } = host();
    await worker.handle({ type: "load", requestId: 1, archive: zip.buffer.slice(zip.byteOffset, zip.byteOffset + zip.byteLength) as ArrayBuffer, rootPath: "p", variantUid: "standard" });
    expect(responses.some((response) => response.type === "frame")).toBe(false);
    jest.advanceTimersByTime(FRAME_INTERVAL_MS);
    const frame = responses.find((response) => response.type === "frame");
    expect(frame?.type === "frame" ? Object.values(frame.state.items) : []).toEqual([expect.objectContaining({ active: true })]);
    jest.advanceTimersByTime(FRAME_INTERVAL_MS * 3);
    expect(responses.filter((response) => response.type === "frame")).toHaveLength(1);
    expect(jest.getTimerCount()).toBe(0);
  });
});
