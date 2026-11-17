import { unzipSync } from "fflate";

import { PackError } from "./errors";
import { parseJsonc } from "./jsonc";

/**
 * The files of a pack, unzipped in memory (story 54.4). Paths are relative to the folder holding `manifest.json`
 * (`rootPath` in the zip). As in PopTracker, a file is looked up first under the active variant's folder, then at
 * the root, so a variant can override any file.
 */
export class PackFiles {
  private readonly files: Map<string, Uint8Array>;
  private readonly decoder = new TextDecoder("utf-8");
  /** Lower-cased path to the first stored path that matches it (story 54.8). */
  private readonly folded = new Map<string, string>();

  private constructor(
    files: Map<string, Uint8Array>,
    private readonly variantUid: string,
  ) {
    this.files = files;
    for (const path of files.keys()) {
      const key = path.toLowerCase();
      if (!this.folded.has(key)) this.folded.set(key, path);
    }
  }

  /** @throws PackError archive_invalid */
  static fromZip(archive: Uint8Array, rootPath: string, variantUid: string): PackFiles {
    let entries: Record<string, Uint8Array>;
    try {
      entries = unzipSync(archive);
    } catch {
      throw new PackError("archive_invalid", "Le zip du pack est illisible.");
    }
    const prefix = rootPath === "" ? "" : `${normalizePath(rootPath)}/`;
    const files = new Map<string, Uint8Array>();
    for (const [name, data] of Object.entries(entries)) {
      const path = normalizePath(name);
      if (path === null || name.endsWith("/") || !path.startsWith(prefix)) continue;
      files.set(path.slice(prefix.length), data);
    }
    return new PackFiles(files, variantUid);
  }

  /** For tests: files given by path. */
  static fromEntries(entries: Record<string, string | Uint8Array>, variantUid: string): PackFiles {
    const encoder = new TextEncoder();
    const files = new Map<string, Uint8Array>();
    for (const [name, content] of Object.entries(entries)) {
      const path = normalizePath(name);
      if (path !== null) files.set(path, typeof content === "string" ? encoder.encode(content) : content);
    }
    return new PackFiles(files, variantUid);
  }

  /**
   * The path the file resolves to, variant folder first, or null. Packs are mostly written and tried on Windows, where
   * a path ignores case: an exact match wins, then one that differs only by case (Spyro 3 asks for `08SpiderTown.json`
   * and ships `08Spidertown.json`, story 54.8).
   */
  resolve(path: string): string | null {
    const clean = normalizePath(path);
    if (clean === null) return null;
    const candidates = this.variantUid === "" ? [clean] : [`${this.variantUid}/${clean}`, clean];
    for (const candidate of candidates) if (this.files.has(candidate)) return candidate;
    for (const candidate of candidates) {
      const found = this.folded.get(candidate.toLowerCase());
      if (found !== undefined) return found;
    }
    return null;
  }

  bytes(path: string): Uint8Array | null {
    const resolved = this.resolve(path);
    return resolved === null ? null : (this.files.get(resolved) ?? null);
  }

  text(path: string): string | null {
    const bytes = this.bytes(path);
    return bytes === null ? null : this.decoder.decode(bytes);
  }

  /** @throws PackError file_missing, json_invalid */
  json(path: string): unknown {
    const text = this.text(path);
    if (text === null) throw new PackError("file_missing", `Fichier introuvable dans le pack : ${path}`, path);
    return parseJsonc(text, path);
  }

  /** Every path of the pack, as stored. */
  paths(): string[] {
    return [...this.files.keys()];
  }
}

/** `a\b/./c` gives `a/b/c`; a path leaving the pack (`..`, absolute, drive) gives null. */
export function normalizePath(path: string): string | null {
  const parts: string[] = [];
  for (const part of path.replaceAll("\\", "/").split("/")) {
    if (part === "" || part === ".") continue;
    if (part === ".." || part.includes(":")) return null;
    parts.push(part);
  }
  return parts.join("/");
}
