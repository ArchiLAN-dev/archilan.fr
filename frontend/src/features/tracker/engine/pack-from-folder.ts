import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

import { PackFiles } from "./pack-files";

/** Tests only: a pack unpacked on disk, as if it came from its zip. */
export function packFromFolder(folder: string, variantUid: string): PackFiles {
  const entries: Record<string, Uint8Array> = {};
  const walk = (dir: string): void => {
    for (const name of readdirSync(dir)) {
      const path = join(dir, name);
      if (statSync(path).isDirectory()) walk(path);
      else entries[relative(folder, path).replaceAll("\\", "/")] = new Uint8Array(readFileSync(path));
    }
  };
  walk(folder);
  return PackFiles.fromEntries(entries, variantUid);
}
