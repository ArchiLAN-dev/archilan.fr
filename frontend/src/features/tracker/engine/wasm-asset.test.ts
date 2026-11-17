import { readFileSync } from "node:fs";
import { join } from "node:path";

/**
 * The Worker loads Lua's WASM from `public/tracker/wasmoon-<version>.wasm` (story 54.4). Upgrading wasmoon without
 * copying its `glue.wasm` there would break every tracker in the browser while Jest (which uses node_modules) stays
 * green: this test catches it.
 */
const FRONTEND = join(__dirname, "..", "..", "..", "..");

function wasmoonVersion(): string {
  const manifest: unknown = JSON.parse(readFileSync(join(FRONTEND, "package.json"), "utf8"));
  if (typeof manifest !== "object" || manifest === null || !("dependencies" in manifest)) throw new Error("package.json");
  const dependencies = manifest.dependencies;
  if (typeof dependencies !== "object" || dependencies === null || !("wasmoon" in dependencies)) throw new Error("wasmoon");
  const version = dependencies.wasmoon;
  if (typeof version !== "string") throw new Error("wasmoon version");
  return version;
}

describe("the Lua WASM served to the Worker", () => {
  it("is the glue.wasm of the installed wasmoon, under its exact version", () => {
    const version = wasmoonVersion();
    expect(version).toMatch(/^\d+\.\d+\.\d+$/);
    const served = readFileSync(join(FRONTEND, "public", "tracker", `wasmoon-${version}.wasm`));
    const installed = readFileSync(require.resolve("wasmoon/dist/glue.wasm"));
    expect(served.equals(installed)).toBe(true);
  });

  it("is the file the Worker asks for", () => {
    const worker = readFileSync(join(__dirname, "tracker.worker.ts"), "utf8");
    expect(worker).toContain(`/tracker/wasmoon-${wasmoonVersion()}.wasm`);
  });
});
