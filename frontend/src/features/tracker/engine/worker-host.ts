import { PackError } from "./errors";
import { PackFiles } from "./pack-files";
import { isTrackerRequest, type TrackerRequest, type TrackerResponse } from "./protocol";
import { EngineError, TrackerEngine, type EngineOptions } from "./tracker-engine";

/**
 * What runs inside the tracker Worker (story 54.4), kept apart from the Worker globals so Jest can drive it: one
 * engine at a time, every request answered with its `requestId`.
 */
export type WorkerHost = {
  handle(message: unknown): Promise<void>;
};

const IMAGE_TYPES: Record<string, string> = {
  png: "image/png",
  gif: "image/gif",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
  webp: "image/webp",
};

export function createWorkerHost(post: (response: TrackerResponse) => void, options: EngineOptions = {}): WorkerHost {
  let engine: TrackerEngine | null = null;

  const fail = (requestId: number | null, error: unknown): void => {
    if (error instanceof EngineError || error instanceof PackError) {
      post({
        type: "error",
        requestId,
        kind: error instanceof EngineError ? error.kind : toKind(error.kind),
        message: error.message,
        ...(error.file === undefined ? {} : { file: error.file }),
        ...(error.line === undefined ? {} : { line: error.line }),
      });
      return;
    }
    post({ type: "error", requestId, kind: "lua_error", message: error instanceof Error ? error.message : String(error) });
  };

  const run = async (request: TrackerRequest): Promise<void> => {
    if (request.type === "load") {
      engine?.dispose();
      engine = null;
      const files = PackFiles.fromZip(new Uint8Array(request.archive), request.rootPath, request.variantUid);
      const loaded = await TrackerEngine.load(files, request.variantUid, {
        ...options,
        onLog: (log) => post({ type: "log", ...log }),
      });
      engine = loaded;
      post({ type: "loaded", requestId: request.requestId, pack: loaded.pack(), state: loaded.initialState() });
      return;
    }
    if (engine === null) throw new EngineError("not_loaded", "Aucun pack n'est chargé.");
    switch (request.type) {
      case "image": {
        const bytes = engine.file(request.path);
        const extension = request.path.split(".").pop()?.toLowerCase() ?? "";
        const blob = bytes === null ? null : new Blob([new Uint8Array(bytes)], { type: IMAGE_TYPES[extension] ?? "application/octet-stream" });
        post({ type: "image", requestId: request.requestId, path: request.path, blob });
        return;
      }
      case "bulk":
        post({ type: "state", requestId: request.requestId, state: engine.applyBulk(request.actions) });
        return;
      default:
        post({ type: "state", requestId: request.requestId, state: engine.apply(request) });
    }
  };

  return {
    async handle(message) {
      if (!isTrackerRequest(message)) {
        post({ type: "error", requestId: requestIdOf(message), kind: "bad_request", message: "Message invalide." });
        return;
      }
      try {
        await run(message);
      } catch (error) {
        fail(message.requestId, error);
      }
    },
  };
}

function requestIdOf(message: unknown): number | null {
  if (typeof message !== "object" || message === null || !("requestId" in message)) return null;
  return typeof message.requestId === "number" ? message.requestId : null;
}

function toKind(kind: string): "archive_invalid" | "file_missing" | "json_invalid" | "lua_error" {
  return kind === "archive_invalid" || kind === "file_missing" || kind === "json_invalid" ? kind : "lua_error";
}
