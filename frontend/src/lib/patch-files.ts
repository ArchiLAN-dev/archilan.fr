import { env } from "@/lib/env";

/**
 * A generated output file and the link that serves it.
 *
 * `url` is the signed public link (story 16.16): it carries its own authorization, so a player can
 * copy it and send it to whoever will play that slot, account or not. It is null only on the legacy
 * weekly path whose output directory is not a session's, where the caller falls back to the
 * authenticated route.
 */
export type PatchFile = { name: string; url: string | null };

/**
 * Reads a `{ data: { files: [...] } }` patch listing.
 *
 * Malformed rows are dropped rather than cast, and anything unexpected yields an empty list - a
 * missing patch shows as "no file", never as a broken panel.
 */
export function parsePatchFiles(payload: unknown): PatchFile[] {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
  const data: unknown = payload.data;
  if (typeof data !== "object" || data === null || !("files" in data)) return [];
  const files: unknown = data.files;
  if (!Array.isArray(files)) return [];

  const parsed: PatchFile[] = [];
  for (const file of files) {
    if (typeof file !== "object" || file === null) continue;
    if (!("name" in file) || typeof file.name !== "string" || file.name === "") continue;
    const url: unknown = "url" in file ? file.url : null;
    parsed.push({ name: file.name, url: typeof url === "string" && url !== "" ? absolute(url) : null });
  }

  return parsed;
}

/**
 * The API signs and returns a path (`/api/v1/public/patches/...`); the browser needs an absolute
 * URL to put in an `href`. Resolving against the API base keeps the origin in one place, and an
 * absolute path replaces the base's own path, so the two never stack.
 */
function absolute(path: string): string {
  try {
    return new URL(path, env.apiBaseUrl).toString();
  } catch {
    return path;
  }
}
