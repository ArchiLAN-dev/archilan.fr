// Story 44.3: a file a world reads from the player, almost always a ROM. Public metadata of the world -
// names, extension, accepted MD5s - never the file itself. `neededAtGeneration` is true once a failed
// generation proved the file necessary; the others are only declared and most serve the client alone.
export type GameRequiredFile = {
  setting: string;
  file: string;
  extension: string;
  description: string | null;
  md5s: string[];
  neededAtGeneration: boolean;
};

export function isGameRequiredFile(v: unknown): v is GameRequiredFile {
  if (typeof v !== "object" || v === null) return false;
  if (!("setting" in v) || typeof v.setting !== "string") return false;
  if (!("file" in v) || typeof v.file !== "string") return false;
  if (!("extension" in v) || typeof v.extension !== "string") return false;
  if (!("description" in v) || (v.description !== null && typeof v.description !== "string")) return false;
  if (!("md5s" in v) || !Array.isArray(v.md5s) || !v.md5s.every((m: unknown) => typeof m === "string")) return false;
  return "neededAtGeneration" in v && typeof v.neededAtGeneration === "boolean";
}

/** The well-formed entries of an unknown payload field; an absent or malformed field reads as none. */
export function asGameRequiredFiles(v: unknown): GameRequiredFile[] {
  return Array.isArray(v) ? v.filter(isGameRequiredFile) : [];
}

/** What a person reads: the world's own description, else the file name it expects. */
export function requiredFileLabel(file: GameRequiredFile): string {
  return file.description ?? file.file;
}

// Story 44.5: a file of a whole party (run, event session), asked once across its games.
export type GenerationFile = GameRequiredFile & { games: string[] };

export function isGenerationFile(v: unknown): v is GenerationFile {
  return (
    isGameRequiredFile(v) &&
    "games" in v &&
    Array.isArray(v.games) &&
    v.games.every((game: unknown) => typeof game === "string")
  );
}

/** The well-formed `generationFiles` of a payload; absent or malformed reads as none. */
export function asGenerationFiles(v: unknown): GenerationFile[] {
  return Array.isArray(v) ? v.filter(isGenerationFile) : [];
}
