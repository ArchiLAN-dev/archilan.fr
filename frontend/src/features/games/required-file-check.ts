import SparkMD5 from "spark-md5";
import { requiredFileLabel, type GameRequiredFile } from "./required-files";

// Story 44.5: the file a launcher provides is checked here, before anything leaves the browser. It stays
// in the page's own state and is never stored: forwarded with the launch, then forgotten.

/** Same cap as the API and the orchestrator: the cartridge ROMs concerned are all well under it. */
export const REQUIRED_FILE_MAX_BYTES = 64 * 1024 * 1024;

const CHUNK_BYTES = 2 * 1024 * 1024;

export type ProvidedFileState =
  | { kind: "missing" }
  | { kind: "checking"; progress: number }
  | { kind: "accepted"; file: File; md5: string | null }
  | { kind: "wrong"; md5: string; expected: string }
  | { kind: "tooLarge" };

/**
 * The file's MD5, read by 2 MB slices so a 64 MB ROM never sits whole in memory nor blocks the page for
 * long. `onProgress` gets a 0..1 fraction after each slice.
 */
export async function md5OfFile(file: Blob, onProgress?: (fraction: number) => void): Promise<string> {
  const spark = new SparkMD5.ArrayBuffer();
  for (let offset = 0; offset < file.size; offset += CHUNK_BYTES) {
    spark.append(await file.slice(offset, offset + CHUNK_BYTES).arrayBuffer());
    onProgress?.(Math.min(1, (offset + CHUNK_BYTES) / file.size));
  }
  return spark.end();
}

/**
 * What the screen says of a chosen file. A world that lists no MD5 checks nothing: any file is accepted,
 * Archipelago will say if it cannot read it.
 */
export async function checkProvidedFile(
  required: GameRequiredFile,
  file: File,
  onProgress?: (fraction: number) => void,
): Promise<ProvidedFileState> {
  if (file.size > REQUIRED_FILE_MAX_BYTES) return { kind: "tooLarge" };
  if (required.md5s.length === 0) return { kind: "accepted", file, md5: null };

  const md5 = await md5OfFile(file, onProgress);
  return required.md5s.includes(md5)
    ? { kind: "accepted", file, md5 }
    : { kind: "wrong", md5, expected: requiredFileLabel(required) };
}

/** The files a launch must carry: those a failed generation proved necessary. */
export function filesToProvide<T extends GameRequiredFile>(files: T[]): T[] {
  return files.filter((file) => file.neededAtGeneration);
}

/** Why the launch is not possible yet, or null when every needed file is accepted. */
export function launchBlocker(needed: GameRequiredFile[], states: Record<string, ProvidedFileState>): string | null {
  for (const file of needed) {
    const state = states[file.setting] ?? { kind: "missing" };
    if (state.kind === "checking") return "Vérification du fichier en cours…";
    if (state.kind !== "accepted") return `Fournis le fichier « ${requiredFileLabel(file)} » pour lancer la partie.`;
  }
  return null;
}

/** The accepted files keyed by their setting, as the launch sends them. */
export function acceptedFiles(states: Record<string, ProvidedFileState>): Record<string, File> {
  const files: Record<string, File> = {};
  for (const [setting, state] of Object.entries(states)) {
    if (state.kind === "accepted") files[setting] = state.file;
  }
  return files;
}

/** The multipart body of a launch with files: `requiredFiles[<setting>]`, the original name kept. */
export function requiredFilesForm(files: Record<string, File>): FormData {
  const form = new FormData();
  for (const [setting, file] of Object.entries(files)) {
    form.append(`requiredFiles[${setting}]`, file, file.name);
  }
  return form;
}

/** One line for a file's state, in the launcher's words. */
export function providedFileMessage(state: ProvidedFileState): string {
  switch (state.kind) {
    case "missing":
      return "Aucun fichier choisi.";
    case "checking":
      return `Vérification… ${Math.round(state.progress * 100)} %`;
    case "accepted":
      return "Fichier reconnu.";
    case "tooLarge":
      return "Fichier trop lourd (64 Mo au plus).";
    case "wrong":
      return `Ce n'est pas le bon fichier : « ${state.expected} » attendu (empreinte ${state.md5}).`;
  }
}
