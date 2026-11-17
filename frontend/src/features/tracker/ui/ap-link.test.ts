import type { ApEvent, ApOutgoing } from "../engine/ap-events";
import {
  ApLink,
  normalizeAddress,
  retryDelay,
  type ApClientLike,
  type ApConnectedPacket,
  type ApLinkStatus,
  type ApSocketHandlers,
} from "./ap-link";

/** Story 54.6: packet translation, batching, resync and reconnection, against a simulated room. */

type FakeClient = ApClientLike & {
  handlers: ApSocketHandlers;
  sent: ({ cmd: "Sync" } | ApOutgoing)[];
  loginArgs: [string, string, string] | null;
  disconnected: boolean;
  resolveLogin(): void;
  rejectLogin(error: Error): void;
};

function fakeClient(): FakeClient {
  let resolve: () => void = () => undefined;
  let reject: (error: Error) => void = () => undefined;
  const fake: FakeClient = {
    handlers: null as unknown as ApSocketHandlers,
    sent: [],
    loginArgs: null,
    disconnected: false,
    listen(handlers) {
      fake.handlers = handlers;
    },
    login(url, slot, password) {
      fake.loginArgs = [url, slot, password];
      return new Promise<void>((res, rej) => {
        resolve = res;
        reject = rej;
      });
    },
    send(packets) {
      fake.sent.push(...packets);
    },
    disconnect() {
      fake.disconnected = true;
    },
    names: () => ({ MM: { items: { 1: "Sword" }, locations: { 10: "Chest" } } }),
    seed: () => "seed-1",
    resolveLogin: () => resolve(),
    rejectLogin: (error) => reject(error),
  };
  return fake;
}

const CONNECTED: ApConnectedPacket = {
  team: 0,
  slot: 3,
  players: [{ team: 0, slot: 3, alias: "Jean", name: "Jean" }],
  missing_locations: [11],
  checked_locations: [10],
  slot_data: { shopsanity: 1 },
  slot_info: { "3": { name: "Jean", game: "MM" } },
};

function item(id: number) {
  return { item: id, location: 0, player: 3, flags: 0 };
}

describe("ApLink", () => {
  let clients: FakeClient[];
  let batches: ApEvent[][];
  let statuses: ApLinkStatus[];
  let timers: { callback: () => void; ms: number; id: number }[];
  let link: ApLink;
  let nextTimer: number;

  function current(): FakeClient {
    const client = clients.at(-1);
    if (client === undefined) throw new Error("no client");
    return client;
  }

  /** Runs the due timers in order: batches first (0 ms), then a reconnection if asked. */
  function tick(maxMs = 0): void {
    for (;;) {
      const due = timers.filter((timer) => timer.ms <= maxMs).sort((a, b) => a.ms - b.ms)[0];
      if (due === undefined) return;
      timers = timers.filter((timer) => timer !== due);
      due.callback();
    }
  }

  function make(secure = false): ApLink {
    return new ApLink({
      createClient: () => {
        const client = fakeClient();
        clients.push(client);
        return client;
      },
      onEvents: (events) => batches.push(events),
      onStatus: (status) => statuses.push(status),
      secure,
      timers: {
        setTimeout: (callback, ms) => {
          nextTimer += 1;
          timers.push({ callback, ms, id: nextTimer });
          return nextTimer;
        },
        clearTimeout: (handle) => {
          timers = timers.filter((timer) => timer.id !== handle);
        },
      },
    });
  }

  beforeEach(() => {
    clients = [];
    batches = [];
    statuses = [];
    timers = [];
    nextTimer = 0;
    link = make();
  });

  async function connect(): Promise<void> {
    link.connect({ address: "localhost:38281", slot: "Jean", password: "secret" });
    current().handlers.connected(CONNECTED);
    current().resolveLogin();
    await Promise.resolve();
  }

  it("logs in with the credentials and translates Connected into one batch: room, clear, done locations", async () => {
    await connect();
    current().handlers.receivedItems({ index: 0, items: [item(1), item(2)] });
    tick();

    expect(current().loginArgs).toEqual(["localhost:38281", "Jean", "secret"]);
    expect(batches).toHaveLength(1);
    const [connected, clear, locations, items] = batches[0]!;
    expect(connected).toMatchObject({ kind: "connected", playerNumber: 3, teamNumber: 0, seed: "seed-1", game: "MM", checked: [10] });
    expect(connected).toMatchObject({ players: [{ slot: 3, alias: "Jean", game: "MM" }], names: { MM: { items: { 1: "Sword" } } } });
    expect(clear).toEqual({ kind: "clear", slotData: { shopsanity: 1 } });
    expect(locations).toEqual({ kind: "locations", locations: [10] });
    expect(items).toEqual({
      kind: "items",
      items: [
        { index: 0, ...item(1) },
        { index: 1, ...item(2) },
      ],
    });
    expect(statuses.at(-1)).toEqual({ kind: "connected", slot: "Jean" });
  });

  it("forwards live updates: new items, new checks only once, data storage keys one by one", async () => {
    await connect();
    current().handlers.receivedItems({ index: 0, items: [item(1)] });
    tick();
    batches = [];

    current().handlers.receivedItems({ index: 1, items: [item(5)] });
    current().handlers.roomUpdate({ checked_locations: [10, 12] });
    current().handlers.roomUpdate({});
    current().handlers.retrieved({ keys: { a: 1, b: null } });
    current().handlers.setReply({ key: "a", value: 2, original_value: 1 });
    current().handlers.locationInfo({ locations: [{ item: 1, location: 11, player: 3, flags: 0 }] });
    current().handlers.bounced({ data: { x: 1 } });
    tick();

    expect(batches).toEqual([
      [
        { kind: "items", items: [{ index: 1, ...item(5) }] },
        { kind: "locations", locations: [12] },
        { kind: "retrieved", key: "a", value: 1 },
        { kind: "retrieved", key: "b", value: null },
        { kind: "setReply", key: "a", value: 2, originalValue: 1 },
        { kind: "scouted", locations: [{ item: 1, location: 11, player: 3, flags: 0 }] },
        { kind: "bounced", data: { x: 1 } },
      ],
    ]);
  });

  it("resyncs: index 0 again clears and replays everything, a gap asks for a Sync, a repeat is dropped", async () => {
    await connect();
    current().handlers.receivedItems({ index: 0, items: [item(1), item(2)] });
    tick();
    batches = [];

    current().handlers.receivedItems({ index: 1, items: [item(2)] });
    tick();
    expect(batches).toEqual([]);

    current().handlers.receivedItems({ index: 5, items: [item(9)] });
    expect(current().sent).toEqual([{ cmd: "Sync" }]);

    current().handlers.receivedItems({ index: 0, items: [item(1), item(2), item(3)] });
    tick();
    expect(batches).toEqual([
      [
        { kind: "clear", slotData: { shopsanity: 1 } },
        { kind: "locations", locations: [10] },
        { kind: "items", items: [0, 1, 2].map((index) => ({ index, ...item(index + 1) })) },
      ],
    ]);
  });

  it("reconnects after a network cut with a growing delay, and starts over without doubling", async () => {
    await connect();
    current().handlers.receivedItems({ index: 0, items: [item(1)] });
    tick();
    const first = current();

    first.handlers.disconnected();
    tick();
    expect(first.disconnected).toBe(true);
    expect(batches.at(-1)).toEqual([{ kind: "disconnected" }]);
    expect(statuses.at(-1)).toEqual({ kind: "reconnecting", attempt: 1, delayMs: 1000 });

    // The retry fails too: the next delay doubles.
    tick(1000);
    current().rejectLogin(new Error("Failed to connect"));
    await Promise.resolve();
    tick();
    expect(statuses.at(-1)).toEqual({ kind: "reconnecting", attempt: 2, delayMs: 2000 });

    tick(2000);
    expect(clients).toHaveLength(3);
    // A late event of the dead socket is ignored.
    first.handlers.receivedItems({ index: 1, items: [item(7)] });
    batches = [];
    current().handlers.connected(CONNECTED);
    current().handlers.receivedItems({ index: 0, items: [item(1)] });
    current().resolveLogin();
    tick();
    expect(batches).toHaveLength(1);
    expect(batches[0]!.map((event) => event.kind)).toEqual(["connected", "clear", "locations", "items"]);
    expect(statuses.at(-1)).toEqual({ kind: "connected", slot: "Jean" });
  });

  it("stops on a refused login, or when the room does not answer at first", async () => {
    link.connect({ address: "localhost:38281", slot: "Nobody", password: "" });
    current().rejectLogin(new Error("Connection was refused by the server. Reason(s): [InvalidSlot"));
    await Promise.resolve();
    expect(statuses.at(-1)).toEqual({ kind: "failed", message: "Ce nom de slot n'existe pas dans la room." });
    expect(timers).toEqual([]);

    link.connect({ address: "localhost:1", slot: "Jean", password: "" });
    current().rejectLogin(new Error("Failed to connect"));
    await Promise.resolve();
    expect(statuses.at(-1)).toEqual({ kind: "failed", message: "La room ne répond pas à cette adresse." });
    expect(timers).toEqual([]);
  });

  it("sends the pack's requests only while connected, and disconnects for good on demand", async () => {
    link.send([{ cmd: "Get", keys: ["k"] }]);
    await connect();
    link.send([{ cmd: "Get", keys: ["k"] }]);
    expect(current().sent).toEqual([{ cmd: "Get", keys: ["k"] }]);

    link.disconnect();
    expect(current().disconnected).toBe(true);
    expect(statuses.at(-1)).toEqual({ kind: "idle" });
    tick(60_000);
    expect(clients).toHaveLength(1);
  });

  it("never puts the password in a status", async () => {
    await connect();
    expect(JSON.stringify(statuses)).not.toContain("secret");
  });
});

describe("addresses and delays", () => {
  it("adds wss:// on an https page and refuses ws:// there", () => {
    expect(normalizeAddress("archipelago.gg:38281", true)).toEqual({ url: "wss://archipelago.gg:38281" });
    expect(normalizeAddress(" localhost:38281 ", false)).toEqual({ url: "localhost:38281" });
    expect(normalizeAddress("ws://host:1", true)).toEqual({ error: "Sur ce site, la room doit être jointe en wss://." });
    expect(normalizeAddress("ws://host:1", false)).toEqual({ url: "ws://host:1" });
    expect(normalizeAddress("http://host", false)).toHaveProperty("error");
    expect(normalizeAddress("", false)).toHaveProperty("error");
  });

  it("waits 1 s, then twice as long each time, up to 30 s", () => {
    expect([1, 2, 3, 4, 5, 6, 7, 20].map(retryDelay)).toEqual([1000, 2000, 4000, 8000, 16000, 30000, 30000, 30000]);
  });
});
