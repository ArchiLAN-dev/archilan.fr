import type { ApEvent, ApGameNames, ApOutgoing, ApPlayer, ApReceivedItem } from "../engine/ap-events";

/**
 * The tracker's connection to an Archipelago room (story 54.6). It lives on the page, not in the Worker: network
 * errors stay where the player sees them. It logs in with the `Tracker` tag (no game, nothing sent to the room but
 * data storage reads), translates the server packets into engine events, batches them, resyncs the items when the
 * server starts over, and reconnects by itself with a growing delay.
 */

/** Server packets the link listens to, reduced to the fields it reads. */
export type ApSocketHandlers = {
  connected(packet: ApConnectedPacket): void;
  receivedItems(packet: { index: number; items: ApNetworkItem[] }): void;
  roomUpdate(packet: { checked_locations?: number[] }): void;
  locationInfo(packet: { locations: ApNetworkItem[] }): void;
  bounced(packet: { data: unknown }): void;
  retrieved(packet: { keys: Record<string, unknown> }): void;
  setReply(packet: { key: string; value: unknown; original_value?: unknown }): void;
  disconnected(): void;
};

export type ApNetworkItem = { item: number; location: number; player: number; flags: number };

export type ApConnectedPacket = {
  team: number;
  slot: number;
  players: { team: number; slot: number; alias: string; name: string }[];
  missing_locations: number[];
  checked_locations: number[];
  slot_data: unknown;
  slot_info: Record<string, { name: string; game: string }>;
};

/** One connection attempt (`ap-client.ts` wraps archipelago.js; tests pass a fake). */
export type ApClientLike = {
  listen(handlers: ApSocketHandlers): void;
  /** Logs in with the `Tracker` tag, no game, every item. Rejects with the server's refusal. */
  login(url: string, slot: string, password: string): Promise<void>;
  send(packets: ({ cmd: "Sync" } | ApOutgoing)[]): void;
  disconnect(): void;
  /** Item and location names by id, per game, from the data package fetched at login. */
  names(): Record<string, ApGameNames>;
  seed(): string;
};

export type ApCredentials = { address: string; slot: string; password: string };

export type ApLinkStatus =
  | { kind: "idle" }
  | { kind: "connecting" }
  | { kind: "connected"; slot: string }
  | { kind: "reconnecting"; attempt: number; delayMs: number }
  | { kind: "failed"; message: string };

type Timers = {
  setTimeout: (callback: () => void, ms: number) => unknown;
  clearTimeout: (handle: unknown) => void;
};

export type ApLinkOptions = {
  createClient: () => ApClientLike;
  onEvents: (events: ApEvent[]) => void;
  onStatus: (status: ApLinkStatus) => void;
  /** `true` on an https page: only `wss://` is allowed there. */
  secure: boolean;
  timers?: Timers;
};

export const FIRST_RETRY_MS = 1_000;
export const MAX_RETRY_MS = 30_000;

/** The delay before the n-th reconnection attempt (1 based): 1 s, 2 s, 4 s... up to 30 s. */
export function retryDelay(attempt: number): number {
  return Math.min(MAX_RETRY_MS, FIRST_RETRY_MS * 2 ** Math.max(0, attempt - 1));
}

/**
 * The address as archipelago.js takes it. On an https page a `ws://` address is refused (the browser would block it
 * anyway) and a bare `host:port` gets `wss://`.
 */
export function normalizeAddress(input: string, secure: boolean): { url: string } | { error: string } {
  const address = input.trim();
  if (address === "") return { error: "Indiquez l'adresse de la room." };
  const scheme = /^([a-z]+):\/\//i.exec(address)?.[1]?.toLowerCase();
  if (scheme === undefined) return { url: secure ? `wss://${address}` : address };
  if (scheme !== "ws" && scheme !== "wss") return { error: "L'adresse doit commencer par wss:// (ou rien)." };
  if (secure && scheme === "ws") return { error: "Sur ce site, la room doit être jointe en wss://." };
  return { url: address };
}

export function connectedEvent(
  packet: ApConnectedPacket,
  names: Record<string, ApGameNames>,
  seed: string,
): Extract<ApEvent, { kind: "connected" }> {
  const players: ApPlayer[] = packet.players.map((player) => ({
    slot: player.slot,
    team: player.team,
    alias: player.alias,
    name: player.name,
    game: packet.slot_info[String(player.slot)]?.game ?? "",
  }));
  return {
    kind: "connected",
    playerNumber: packet.slot,
    teamNumber: packet.team,
    seed,
    game: packet.slot_info[String(packet.slot)]?.game ?? "",
    checked: [...packet.checked_locations],
    missing: [...packet.missing_locations],
    players,
    names,
  };
}

function receivedItems(index: number, items: ApNetworkItem[]): ApReceivedItem[] {
  return items.map((item, offset) => ({ index: index + offset, ...item }));
}

export class ApLink {
  private client: ApClientLike | null = null;
  private credentials: ApCredentials | null = null;
  private queue: ApEvent[] = [];
  private flushTimer: unknown = null;
  private retryTimer: unknown = null;
  private attempt = 0;
  /** What the room said at the last `Connected`: a resync replays it. */
  private slotData: unknown = {};
  private readonly checked = new Set<number>();
  private items: ApReceivedItem[] = [];
  /** Bumped on every new client: events of an old socket are dropped. */
  private generation = 0;
  private status: ApLinkStatus = { kind: "idle" };
  private readonly timers: Timers;

  constructor(private readonly options: ApLinkOptions) {
    this.timers = options.timers ?? {
      setTimeout: (callback, ms) => setTimeout(callback, ms),
      clearTimeout: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
    };
  }

  connect(credentials: ApCredentials): void {
    const address = normalizeAddress(credentials.address, this.options.secure);
    if ("error" in address) {
      this.setStatus({ kind: "failed", message: address.error });
      return;
    }
    this.stop();
    this.credentials = { ...credentials, address: address.url };
    this.attempt = 0;
    this.open(false);
  }

  /** The pack's data storage requests. Dropped while the room is not connected: the next Clear asks again. */
  send(packets: ApOutgoing[]): void {
    if (this.status.kind !== "connected" || this.client === null) return;
    try {
      this.client.send(packets);
    } catch {
      // The socket just closed: `disconnected` follows.
    }
  }

  disconnect(): void {
    this.stop();
    this.credentials = null;
    this.setStatus({ kind: "idle" });
  }

  private open(retrying: boolean): void {
    const credentials = this.credentials;
    if (credentials === null) return;
    this.generation += 1;
    const generation = this.generation;
    const client = this.options.createClient();
    this.client = client;
    if (!retrying) this.setStatus({ kind: "connecting" });
    const live = () => generation === this.generation;

    client.listen({
      connected: (packet) => {
        if (!live()) return;
        this.attempt = 0;
        this.slotData = packet.slot_data ?? {};
        this.checked.clear();
        for (const id of packet.checked_locations) this.checked.add(id);
        this.items = [];
        // A new connection, first or not: the pack starts over from the room's state.
        this.push(
          connectedEvent(packet, client.names(), client.seed()),
          { kind: "clear", slotData: this.slotData },
          { kind: "locations", locations: [...this.checked] },
        );
        this.setStatus({ kind: "connected", slot: credentials.slot });
      },
      receivedItems: (packet) => {
        if (live()) this.receive(packet.index, packet.items);
      },
      roomUpdate: (packet) => {
        if (!live() || packet.checked_locations === undefined) return;
        const fresh = packet.checked_locations.filter((id) => !this.checked.has(id));
        for (const id of fresh) this.checked.add(id);
        if (fresh.length > 0) this.push({ kind: "locations", locations: fresh });
      },
      locationInfo: (packet) => {
        if (live()) this.push({ kind: "scouted", locations: packet.locations });
      },
      bounced: (packet) => {
        if (live()) this.push({ kind: "bounced", data: packet.data });
      },
      retrieved: (packet) => {
        if (!live()) return;
        for (const [key, value] of Object.entries(packet.keys)) this.push({ kind: "retrieved", key, value });
      },
      setReply: (packet) => {
        if (live()) this.push({ kind: "setReply", key: packet.key, value: packet.value, originalValue: packet.original_value ?? null });
      },
      disconnected: () => {
        if (live()) this.lost();
      },
    });

    client
      .login(credentials.address, credentials.slot, credentials.password)
      .catch((error: unknown) => {
        if (!live()) return;
        const message = error instanceof Error ? error.message : String(error);
        // A refused login (wrong slot or password) will not get better by retrying.
        if (/refused|InvalidSlot|InvalidPassword|InvalidGame|IncompatibleVersion/i.test(message)) {
          this.stop();
          this.setStatus({ kind: "failed", message: refusedMessage(message) });
          return;
        }
        if (this.status.kind === "connecting") {
          this.stop();
          this.setStatus({ kind: "failed", message: "La room ne répond pas à cette adresse." });
          return;
        }
        this.lost();
      });
  }

  /**
   * Items arrive in index order. Index 0 after items were applied means the server starts over (a `Sync`, or a
   * reconnection): the pack is cleared and replays everything, so nothing counts twice. A gap asks for a `Sync`.
   */
  private receive(index: number, items: ApNetworkItem[]): void {
    const received = receivedItems(index, items);
    if (index === 0 && this.items.length > 0) {
      this.items = received;
      this.push(
        { kind: "clear", slotData: this.slotData },
        { kind: "locations", locations: [...this.checked] },
        { kind: "items", items: received },
      );
      return;
    }
    if (index > this.items.length) {
      this.client?.send([{ cmd: "Sync" }]);
      return;
    }
    const fresh = received.filter((item) => item.index >= this.items.length);
    if (fresh.length === 0) return;
    this.items = [...this.items, ...fresh];
    this.push({ kind: "items", items: fresh });
  }

  private lost(): void {
    if (this.retryTimer !== null) return;
    this.dropClient();
    this.push({ kind: "disconnected" });
    if (this.credentials === null) return;
    this.attempt += 1;
    const delayMs = retryDelay(this.attempt);
    this.setStatus({ kind: "reconnecting", attempt: this.attempt, delayMs });
    this.retryTimer = this.timers.setTimeout(() => {
      this.retryTimer = null;
      this.open(true);
    }, delayMs);
  }

  /** Events of one burst go to the engine together: one logic pass. */
  private push(...events: ApEvent[]): void {
    this.queue.push(...events);
    if (this.flushTimer !== null) return;
    this.flushTimer = this.timers.setTimeout(() => {
      this.flushTimer = null;
      const batch = this.queue;
      this.queue = [];
      if (batch.length > 0) this.options.onEvents(batch);
    }, 0);
  }

  private dropClient(): void {
    this.generation += 1;
    const client = this.client;
    this.client = null;
    try {
      client?.disconnect();
    } catch {
      // Already closed.
    }
  }

  private stop(): void {
    if (this.retryTimer !== null) this.timers.clearTimeout(this.retryTimer);
    this.retryTimer = null;
    this.dropClient();
  }

  private setStatus(status: ApLinkStatus): void {
    this.status = status;
    this.options.onStatus(status);
  }
}

function refusedMessage(message: string): string {
  if (/InvalidSlot/i.test(message)) return "Ce nom de slot n'existe pas dans la room.";
  if (/InvalidPassword/i.test(message)) return "Mot de passe de la room incorrect.";
  if (/IncompatibleVersion/i.test(message)) return "La version d'Archipelago de la room n'est pas prise en charge.";
  return "La room a refusé la connexion.";
}
