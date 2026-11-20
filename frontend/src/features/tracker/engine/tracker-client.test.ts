import {
  TRACKER_TIMEOUT_MESSAGE,
  TrackerClient,
  TrackerClientError,
  type WorkerLike,
} from "./tracker-client";

/** A Worker that records what it receives and answers only when told to. */
class FakeWorker implements WorkerLike {
  readonly received: unknown[] = [];
  terminated = false;
  private messageListener: ((data: unknown) => void) | null = null;
  private errorListener: ((message: string) => void) | null = null;

  postMessage(message: unknown): void {
    this.received.push(message);
  }

  terminate(): void {
    this.terminated = true;
  }

  onMessage(listener: (data: unknown) => void): void {
    this.messageListener = listener;
  }

  onError(listener: (message: string) => void): void {
    this.errorListener = listener;
  }

  reply(data: unknown): void {
    this.messageListener?.(data);
  }

  crash(message: string): void {
    this.errorListener?.(message);
  }
}

function setup(timeoutMs = 10_000) {
  const worker = new FakeWorker();
  const logs: string[] = [];
  const client = new TrackerClient({ createWorker: () => worker, timeoutMs, onLog: (log) => logs.push(log.message) });
  return { worker, client, logs };
}

const EMPTY_STATE = { items: {}, sections: {}, locations: {} };

describe("TrackerClient", () => {
  afterEach(() => jest.useRealTimers());

  it("matches each answer to its request", async () => {
    const { worker, client } = setup();
    const first = client.apply({ type: "itemClick", itemId: 1, button: "left" });
    const second = client.apply({ type: "sectionClear", sectionId: 4 });
    expect(worker.received).toEqual([
      { type: "itemClick", itemId: 1, button: "left", requestId: 1 },
      { type: "sectionClear", sectionId: 4, requestId: 2 },
    ]);

    worker.reply({ type: "state", requestId: 2, state: { ...EMPTY_STATE, sections: { 4: { available: 0 } } } });
    worker.reply({ type: "state", requestId: 1, state: EMPTY_STATE });
    await expect(second).resolves.toMatchObject({ sections: { 4: { available: 0 } } });
    await expect(first).resolves.toEqual(EMPTY_STATE);
  });

  it("turns a Worker error into a typed rejection and forwards the logs", async () => {
    const { worker, client, logs } = setup();
    const load = client.load(new ArrayBuffer(1), "", "standard");
    worker.reply({ type: "log", level: "info", message: "hello" });
    worker.reply({ type: "error", requestId: 1, kind: "lua_error", message: "boom", file: "scripts/init.lua", line: 3 });
    await expect(load).rejects.toMatchObject({ kind: "lua_error", file: "scripts/init.lua", line: 3 });
    expect(logs).toEqual(["hello"]);
  });

  it("kills a Worker that does not answer in time and fails every pending request", async () => {
    jest.useFakeTimers();
    const { worker, client } = setup(10_000);
    const stuck = client.apply({ type: "itemClick", itemId: 1, button: "left" });
    const queued = client.image("images/a.png");

    jest.advanceTimersByTime(9_999);
    expect(worker.terminated).toBe(false);
    jest.advanceTimersByTime(1);

    expect(worker.terminated).toBe(true);
    await expect(stuck).rejects.toEqual(new TrackerClientError("timeout", TRACKER_TIMEOUT_MESSAGE));
    await expect(queued).rejects.toMatchObject({ kind: "timeout" });
    await expect(client.apply({ type: "sectionUnclear", sectionId: 1 })).rejects.toMatchObject({ kind: "timeout" });
  });

  it("does not time out a request that was answered", async () => {
    jest.useFakeTimers();
    const { worker, client } = setup(10_000);
    const answered = client.apply({ type: "itemClick", itemId: 1, button: "left" });
    worker.reply({ type: "state", requestId: 1, state: EMPTY_STATE });
    await answered;
    jest.advanceTimersByTime(20_000);
    expect(worker.terminated).toBe(false);
  });

  it("stops when the Worker crashes", async () => {
    const { worker, client } = setup();
    const pending = client.apply({ type: "itemClick", itemId: 1, button: "left" });
    worker.crash("wasm crashed");
    await expect(pending).rejects.toMatchObject({ kind: "worker_failed", message: "wasm crashed" });
    expect(worker.terminated).toBe(true);
  });

  it("ignores messages that are not responses", async () => {
    const { worker, client } = setup();
    const pending = client.apply({ type: "itemClick", itemId: 1, button: "left" });
    worker.reply({ nonsense: true });
    worker.reply({ type: "state", requestId: 99, state: EMPTY_STATE });
    worker.reply({ type: "state", requestId: 1, state: EMPTY_STATE });
    await expect(pending).resolves.toEqual(EMPTY_STATE);
  });

  it("pings a quiet Worker and stops it when the ping goes unanswered (story 54.13)", () => {
    jest.useFakeTimers();
    const worker = new FakeWorker();
    const client = new TrackerClient({ createWorker: () => worker, timeoutMs: 1_000, heartbeatMs: 500 });
    jest.advanceTimersByTime(500);
    expect(worker.received).toEqual([{ type: "ping", requestId: 1 }]);
    worker.reply({ type: "pong", requestId: 1 });
    jest.advanceTimersByTime(900);
    expect(worker.terminated).toBe(false);

    // The second ping stays unanswered: the Worker is stuck.
    jest.advanceTimersByTime(1_200);
    expect(worker.terminated).toBe(true);
    return expect(client.image("a.png")).rejects.toMatchObject({ kind: "timeout" });
  });

  it("relays to the room only the reads a pack may ask for (story 54.13)", () => {
    const sent: unknown[] = [];
    const worker = new FakeWorker();
    new TrackerClient({ createWorker: () => worker, onApSend: (packets) => sent.push(packets) });
    worker.reply({ type: "apSend", packets: [{ cmd: "Get", keys: ["a"] }, { cmd: "SetNotify", keys: ["b"] }] });
    worker.reply({ type: "apSend", packets: [{ cmd: "Say", text: "!release" }] });
    worker.reply({ type: "apSend", packets: [{ cmd: "Get", keys: [1] }] });
    worker.reply({ type: "apSend", packets: [{ cmd: "Set", keys: ["a"] }] });
    worker.reply({ type: "apSend", packets: [{ cmd: "Get", keys: ["a"], key: "x", operations: [] }] });
    expect(sent).toEqual([[{ cmd: "Get", keys: ["a"] }, { cmd: "SetNotify", keys: ["b"] }]]);
  });
});
