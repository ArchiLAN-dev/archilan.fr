/** Tolerant readers for pack JSON (story 54.4): a wrong type reads as absent, as PopTracker does. */
export type JsonObject = Record<string, unknown>;

export function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function readString(source: JsonObject, key: string): string | null {
  const value = source[key];
  return typeof value === "string" ? value : null;
}

export function readNumber(source: JsonObject, key: string): number | null {
  const value = source[key];
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && value.trim() !== "" && Number.isFinite(Number(value))) return Number(value);
  return null;
}

export function readBoolean(source: JsonObject, key: string): boolean | null {
  const value = source[key];
  return typeof value === "boolean" ? value : null;
}

export function readObjects(source: JsonObject, key: string): JsonObject[] {
  const value = source[key];
  return Array.isArray(value) ? value.filter(isJsonObject) : [];
}

/** `"a, b,c"` gives `["a", "b", "c"]`. */
export function splitCodes(value: string | null): string[] {
  if (value === null) return [];
  return value
    .split(",")
    .map((code) => code.trim())
    .filter((code) => code !== "");
}
