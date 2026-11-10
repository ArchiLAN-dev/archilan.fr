// Story 3.17: a jsdom environment for the tests that drive a real DOM (keyboard, focus).
//
// jest.setup.ts starts msw for every suite, and msw needs the fetch API that Node provides but jsdom hides
// (`Request is not defined`). This environment hands Node's globals back, the way `jest-fixed-jsdom` does.
// Use it with a `@jest-environment ./src/tests/jsdom-environment.mjs` docblock.
import { TestEnvironment } from "jest-environment-jsdom";

const NODE_GLOBALS = [
  "fetch",
  "Request",
  "Response",
  "Headers",
  "FormData",
  "Blob",
  "File",
  "ReadableStream",
  "WritableStream",
  "TransformStream",
  "TextEncoder",
  "TextDecoder",
  "TextEncoderStream",
  "TextDecoderStream",
  "BroadcastChannel",
  "AbortController",
  "AbortSignal",
  "structuredClone",
  "URL",
  "URLSearchParams",
  "WebSocket",
];

class NodeFetchJsdomEnvironment extends TestEnvironment {
  constructor(...args) {
    super(...args);
    for (const name of NODE_GLOBALS) {
      if (name in globalThis) this.global[name] = globalThis[name];
    }
  }
}

export default NodeFetchJsdomEnvironment;
