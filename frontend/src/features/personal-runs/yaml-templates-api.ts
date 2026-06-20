import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

export type YamlTemplate = {
  id: string;
  name: string;
  gameId: string;
  yaml: string;
  updatedAt: string;
};

/** Discriminated result: a saved template, or a validation error code (e.g. `template_name_taken`). */
export type SaveTemplateResult = { ok: true; template: YamlTemplate } | { ok: false; code: string };

function isYamlTemplate(v: unknown): v is YamlTemplate {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "name") &&
    hasStringProp(v, "gameId") &&
    hasStringProp(v, "yaml") &&
    hasStringProp(v, "updatedAt")
  );
}

function errorCodeOf(json: unknown): string | null {
  if (typeof json !== "object" || json === null || !("error" in json)) return null;
  const error = json.error;
  if (typeof error === "object" && error !== null && hasStringProp(error, "code")) return error.code;
  return null;
}

export async function fetchYamlTemplates(gameId: string): Promise<YamlTemplate[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/yaml-templates?gameId=${encodeURIComponent(gameId)}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) {
      return null;
    }
    return json.data.every(isYamlTemplate) ? json.data : null;
  } catch {
    return null;
  }
}

async function writeTemplate(
  url: string,
  method: "POST" | "PUT",
  body: Record<string, string>,
): Promise<SaveTemplateResult | null> {
  try {
    const res = await apiFetch(url, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    if (res.ok) {
      const json: unknown = await res.json();
      if (typeof json === "object" && json !== null && "data" in json && isYamlTemplate(json.data)) {
        return { ok: true, template: json.data };
      }
      return null;
    }
    if (res.status === 422) {
      const json: unknown = await res.json().catch(() => null);
      return { ok: false, code: errorCodeOf(json) ?? "invalid" };
    }
    return null;
  } catch {
    return null;
  }
}

export function createYamlTemplate(input: {
  gameId: string;
  name: string;
  yaml: string;
}): Promise<SaveTemplateResult | null> {
  return writeTemplate(`${env.apiBaseUrl}/yaml-templates`, "POST", input);
}

export function updateYamlTemplate(
  id: string,
  input: { name?: string; yaml?: string },
): Promise<SaveTemplateResult | null> {
  const body: Record<string, string> = {};
  if (input.name !== undefined) body.name = input.name;
  if (input.yaml !== undefined) body.yaml = input.yaml;
  return writeTemplate(`${env.apiBaseUrl}/yaml-templates/${id}`, "PUT", body);
}

export async function deleteYamlTemplate(id: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/yaml-templates/${id}`, { method: "DELETE" });
    return res.ok;
  } catch {
    return false;
  }
}
