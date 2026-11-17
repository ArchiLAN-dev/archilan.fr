import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";

import { parseJsonc } from "./jsonc";
import { packFromFolder } from "./pack-from-folder";
import { TrackerEngine } from "./tracker-engine";

/**
 * Story 54.8: the compatibility campaign. Loads every variant of every pack found under TRACKER_CAMPAIGN_DIR (one
 * folder per pack, manifest.json up to three levels down) and writes what each one reported to TRACKER_CAMPAIGN_OUT.
 * Skipped without the folder: the packs are third-party and not committed.
 *
 *   TRACKER_CAMPAIGN_DIR=/path/to/packs TRACKER_CAMPAIGN_OUT=report.json pnpm jest pack-campaign
 */
const root = process.env.TRACKER_CAMPAIGN_DIR ?? "";
const out = process.env.TRACKER_CAMPAIGN_OUT ?? "";
const run = root !== "" && existsSync(root) ? describe : describe.skip;

/** The widgets the screen draws (ui/tracker-layout.tsx); any other type is reported there as not drawn. */
const DRAWN = new Set(["container", "dock", "array", "tabbed", "group", "item", "itemgrid", "layout", "map", "text", "canvas"]);

function findManifest(dir: string, depth = 0): string | null {
  if (existsSync(join(dir, "manifest.json"))) return dir;
  if (depth >= 3) return null;
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      const found = findManifest(path, depth + 1);
      if (found !== null) return found;
    }
  }
  return null;
}

function widgetTypes(node: unknown, into: Set<string>): void {
  if (Array.isArray(node)) {
    for (const child of node) widgetTypes(child, into);
    return;
  }
  if (typeof node !== "object" || node === null) return;
  const type = (node as { type?: unknown }).type;
  if (typeof type === "string") into.add(type);
  for (const [key, value] of Object.entries(node)) {
    if (key === "content" || key === "tabs" || key === "rows" || Array.isArray(value) || typeof value === "object") widgetTypes(value, into);
  }
}

type Result = { pack: string; variant: string; ms: number; failed: string | null; errors: string[]; unsupported: string[]; widgets: string[] };

run("compatibility campaign", () => {
  const results: Result[] = [];

  afterAll(() => {
    if (out !== "") writeFileSync(out, JSON.stringify(results, null, 2));
  });

  it("tries every variant of every pack", async () => {
    for (const name of readdirSync(root)) {
      const path = join(root, name);
      if (statSync(path).isDirectory()) await tryPack(name, path, results);
    }
  }, 600_000);
});

async function tryPack(name: string, path: string, results: Result[]): Promise<void> {
    const folder = findManifest(path);
    if (folder === null) {
      results.push({ pack: name, variant: "-", ms: 0, failed: "pas de manifest.json", errors: [], unsupported: [], widgets: [] });
      return;
    }
    const manifest = parseJsonc(readFileSync(join(folder, "manifest.json"), "utf8")) as { variants?: Record<string, unknown> };
    const variants = Object.keys(manifest.variants ?? {});
    for (const variant of variants.length === 0 ? [""] : variants) {
      const started = performance.now();
      try {
        const engine = await TrackerEngine.load(packFromFolder(folder, variant), variant);
        engine.initialState();
        const widgets = new Set<string>();
        widgetTypes(engine.pack().layouts, widgets);
        results.push({
          pack: name,
          variant,
          ms: Math.round(performance.now() - started),
          failed: null,
          errors: engine.logs.filter((log) => log.level === "error" && !log.message.startsWith("Fonction non prise en charge")).map((log) => log.message),
          unsupported: [...engine.unsupportedCalls],
          widgets: [...widgets].filter((type) => !DRAWN.has(type)),
        });
        engine.dispose();
      } catch (error) {
        results.push({
          pack: name,
          variant,
          ms: Math.round(performance.now() - started),
          failed: error instanceof Error ? error.message : String(error),
          errors: [],
          unsupported: [],
          widgets: [],
        });
      }
    }
}
