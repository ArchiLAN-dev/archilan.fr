import { createWorkerHost } from "./worker-host";

/**
 * The tracker Worker (story 54.4). A classic Worker, not a module one: wasmoon's Emscripten glue only recognises a
 * Worker through `importScripts`. The Lua WASM is served from `public/tracker/` under its version.
 */
const host = createWorkerHost((response) => self.postMessage(response), {
  wasmUri: "/tracker/wasmoon-1.16.0.wasm",
});

self.addEventListener("message", (event: MessageEvent<unknown>) => {
  void host.handle(event.data);
});
