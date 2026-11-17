import type { WorkerLike } from "./tracker-client";

/**
 * Starts the tracker Worker (story 54.4). Kept alone in its file: `import.meta.url` is for the bundler, Jest never
 * loads this module (the client takes the factory as a parameter).
 */
export function createTrackerWorker(): WorkerLike {
  const worker = new Worker(new URL("./tracker.worker.ts", import.meta.url));
  return {
    postMessage: (message, transfer) => worker.postMessage(message, transfer),
    terminate: () => worker.terminate(),
    onMessage: (listener) => worker.addEventListener("message", (event: MessageEvent<unknown>) => listener(event.data)),
    onError: (listener) =>
      worker.addEventListener("error", (event) => {
        event.preventDefault();
        listener(event.message);
      }),
  };
}
