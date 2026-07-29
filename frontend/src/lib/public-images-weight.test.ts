import { readdirSync, statSync } from "fs";
import { join } from "path";

/**
 * Gate against raw image exports in `public/images` (epic 34 follow-up): a 10.6 MB hero photo and a
 * 2.4 MB logo shipped to production and sank the home page's Lighthouse performance to 45 (LCP 95 s
 * on throttled mobile - the optimizer served them as-is). Any static image needing more than this
 * budget should be resized/re-encoded before commit (the repo's sources are what production serves).
 */
const MAX_BYTES = 500 * 1024;
const IMAGES_ROOT = join(__dirname, "..", "..", "public", "images");

function walk(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = join(dir, entry.name);
    return entry.isDirectory() ? walk(full) : [full];
  });
}

describe("public images weight", () => {
  it("keeps every static image under 500 KB", () => {
    const offenders = walk(IMAGES_ROOT)
      .filter((file) => statSync(file).size > MAX_BYTES)
      .map((file) => `${file} (${Math.round(statSync(file).size / 1024)} KB)`);
    expect(offenders).toEqual([]);
  });
});
