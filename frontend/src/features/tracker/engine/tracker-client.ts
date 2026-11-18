import type { ApEvent, ApOutgoing } from "./ap-events";
import type {
  EngineAction,
  LoadedPack,
  LogLevel,
  StateUpdate,
  TrackerError,
  TrackerErrorKind,
  TrackerRequest,
  TrackerResponse,
} from "./protocol";
import { isTrackerResponse } from "./protocol";

/**
 * The page side of the tracker Worker (story 54.4). Every request waits for its answer at most `timeoutMs`
 * (10 s): past that, the pack is stuck (an endless Lua loop, say), the Worker is killed and every pending request
 * fails with `timeout`. The client is then dead; a new one starts a new Worker.
 */
/** The Worker as the client sees it; `createTrackerWorker` adapts a real one, tests pass a fake. */
export type WorkerLike = {
  postMessage(message: unknown, transfer: Transferable[]): void;
  terminate(): void;
  onMessage(listener: (data: unknown) => void): void;
  /** The Worker died (uncaught error, WASM that failed to load...). */
  onError(listener: (message: string) => void): void;
};

export type TrackerClientOptions = {
  createWorker: () => WorkerLike;
  timeoutMs?: number;
  onLog?: (log: { level: LogLevel; message: string }) => void;
  /** Story 54.6: the pack asks the Archipelago server for data storage keys. */
  onApSend?: (packets: ApOutgoing[]) => void;
  /** Story 54.11: what the pack's frame handlers changed, unasked. */
  onFrame?: (state: StateUpdate) => void;
};

export const TRACKER_TIMEOUT_MS = 10_000;

export const TRACKER_TIMEOUT_MESSAGE = "Le pack ne répond plus.";

export class TrackerClientError extends Error implements TrackerError {
  constructor(
    readonly kind: TrackerErrorKind,
    message: string,
    readonly file?: string,
    readonly line?: number,
  ) {
    super(message);
    this.name = "TrackerClientError";
  }
}

type Pending = {
  resolve: (response: TrackerResponse) => void;
  reject: (error: TrackerClientError) => void;
  timer: ReturnType<typeof setTimeout>;
};

type RequestBody = TrackerRequest extends infer R ? (R extends TrackerRequest ? Omit<R, "requestId"> : never) : never;

export class TrackerClient {
  private readonly worker: WorkerLike;
  private readonly pending = new Map<number, Pending>();
  private readonly timeoutMs: number;
  private nextRequestId = 1;
  private failure: TrackerClientError | null = null;

  constructor(private readonly options: TrackerClientOptions) {
    this.timeoutMs = options.timeoutMs ?? TRACKER_TIMEOUT_MS;
    this.worker = options.createWorker();
    this.worker.onMessage((data) => this.receive(data));
    this.worker.onError((message) =>
      this.stop(new TrackerClientError("worker_failed", message || "Le moteur du tracker s'est arrêté.")),
    );
  }

  /** The archive is transferred to the Worker: the buffer is unusable afterwards. */
  async load(archive: ArrayBuffer, rootPath: string, variantUid: string): Promise<{ pack: LoadedPack; state: StateUpdate }> {
    const response = await this.send({ type: "load", archive, rootPath, variantUid }, [archive]);
    if (response.type !== "loaded") throw unexpected(response);
    return { pack: response.pack, state: response.state };
  }

  async apply(action: EngineAction): Promise<StateUpdate> {
    return this.expectState(await this.send(action));
  }

  async bulk(actions: EngineAction[]): Promise<StateUpdate> {
    return this.expectState(await this.send({ type: "bulk", actions }));
  }

  /** Story 54.6: Archipelago events, applied in one batch. */
  async ap(events: ApEvent[]): Promise<StateUpdate> {
    return this.expectState(await this.send({ type: "ap", events }));
  }

  async image(path: string): Promise<Blob | null> {
    const response = await this.send({ type: "image", path });
    if (response.type !== "image") throw unexpected(response);
    return response.blob;
  }

  dispose(): void {
    this.stop(new TrackerClientError("worker_failed", "Le tracker est fermé."));
  }

  private expectState(response: TrackerResponse): StateUpdate {
    if (response.type !== "state") throw unexpected(response);
    return response.state;
  }

  private send(body: RequestBody, transfer: Transferable[] = []): Promise<TrackerResponse> {
    if (this.failure !== null) return Promise.reject(this.failure);
    const requestId = this.nextRequestId++;
    return new Promise<TrackerResponse>((resolve, reject) => {
      const timer = setTimeout(
        () => this.stop(new TrackerClientError("timeout", TRACKER_TIMEOUT_MESSAGE)),
        this.timeoutMs,
      );
      this.pending.set(requestId, { resolve, reject, timer });
      this.worker.postMessage({ ...body, requestId }, transfer);
    });
  }

  private receive(data: unknown): void {
    if (!isTrackerResponse(data)) return;
    if (data.type === "log") {
      this.options.onLog?.({ level: data.level, message: data.message });
      return;
    }
    if (data.type === "apSend") {
      this.options.onApSend?.(data.packets);
      return;
    }
    if (data.type === "frame") {
      this.options.onFrame?.(data.state);
      return;
    }
    if (data.requestId === null) return;
    const pending = this.pending.get(data.requestId);
    if (pending === undefined) return;
    this.pending.delete(data.requestId);
    clearTimeout(pending.timer);
    if (data.type === "error") {
      pending.reject(new TrackerClientError(data.kind, data.message, data.file, data.line));
      return;
    }
    pending.resolve(data);
  }

  private stop(error: TrackerClientError): void {
    if (this.failure !== null) return;
    this.failure = error;
    this.worker.terminate();
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(error);
    }
    this.pending.clear();
  }
}

function unexpected(response: TrackerResponse): TrackerClientError {
  return new TrackerClientError("worker_failed", `Réponse inattendue du moteur : ${response.type}`);
}
