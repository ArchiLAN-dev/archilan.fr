import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ── Rule tree ────────────────────────────────────────────────────────────────
// Mirrors the backend AchievementRule tree (story 30.16): a node is either a
// boolean group (all/any/none) or a leaf condition (fact / operator / value).

export type RuleGroupOp = "all" | "any" | "none";
export type RuleOperator = ">=" | ">" | "=" | "!=" | "<=" | "<" | "between";

export type RuleCondition = {
  fact: string;
  operator: RuleOperator;
  value: number;
  value2?: number;
};

export type RuleGroup = {
  op: RuleGroupOp;
  rules: RuleNode[];
};

export type RuleNode = RuleGroup | RuleCondition;

export function isRuleGroup(node: RuleNode): node is RuleGroup {
  return "op" in node;
}

export type AchievementDefinition = {
  id: string;
  key: string;
  name: string;
  description: string;
  rule: RuleGroup;
  active: boolean;
  position: number;
  customImageKey: string | null;
  customImageUrl: string | null;
};

export type AchievementFactOption = { key: string; label: string };
export type AchievementEventOption = { id: string; title: string };

export type AchievementFormOptions = {
  facts: AchievementFactOption[];
  operators: RuleOperator[];
  groupOps: RuleGroupOp[];
  events: AchievementEventOption[];
};

export type AchievementDashboard = {
  definitions: AchievementDefinition[];
  options: AchievementFormOptions;
};

export type CreateAchievementPayload = {
  key: string;
  name: string;
  description: string;
  rule: RuleGroup;
  customImageKey?: string | null;
};

export type UpdateAchievementPayload = {
  name: string;
  description: string;
  rule: RuleGroup;
  customImageKey?: string | null;
};

export type MutationResult<T> = { ok: true; value: T } | { ok: false; error: string };

// ── Fetch ────────────────────────────────────────────────────────────────────

export async function fetchAchievementDashboard(): Promise<AchievementDashboard | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/community/achievements`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    const definitions = json.data.filter(isAchievementDefinition);

    const meta: unknown = "meta" in json ? json.meta : null;
    const rawOptions: unknown =
      typeof meta === "object" && meta !== null && "options" in meta ? meta.options : null;
    const options = parseOptions(rawOptions);
    if (options === null) return null;

    return { definitions, options };
  } catch {
    return null;
  }
}

export async function createAchievement(
  payload: CreateAchievementPayload,
): Promise<MutationResult<AchievementDefinition>> {
  return mutateDefinition(`${env.apiBaseUrl}/admin/community/achievements`, "POST", payload);
}

export async function updateAchievement(
  id: string,
  payload: UpdateAchievementPayload,
): Promise<MutationResult<AchievementDefinition>> {
  return mutateDefinition(
    `${env.apiBaseUrl}/admin/community/achievements/${encodeURIComponent(id)}`,
    "PATCH",
    payload,
  );
}

export async function setAchievementActive(id: string, active: boolean): Promise<boolean> {
  return noContent(
    `${env.apiBaseUrl}/admin/community/achievements/${encodeURIComponent(id)}/active`,
    { active },
  );
}

export async function reorderAchievements(ids: string[]): Promise<boolean> {
  return noContent(`${env.apiBaseUrl}/admin/community/achievements/reorder`, { ids });
}

export async function uploadAchievementImage(
  file: File,
): Promise<{ key: string; imageUrl: string } | null> {
  try {
    const body = new FormData();
    body.append("file", file);
    const res = await apiFetch(`${env.apiBaseUrl}/admin/community/achievements/image`, {
      method: "POST",
      body,
    });
    if (!res.ok) return null;
    const json: unknown = await res.json();
    const data: unknown =
      typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data !== "object" || data === null) return null;
    if (!("key" in data) || typeof data.key !== "string") return null;
    if (!("imageUrl" in data) || typeof data.imageUrl !== "string") return null;
    return { key: data.key, imageUrl: data.imageUrl };
  } catch {
    return null;
  }
}

// ── Internals ─────────────────────────────────────────────────────────────────

async function mutateDefinition(
  url: string,
  method: "POST" | "PATCH",
  payload: CreateAchievementPayload | UpdateAchievementPayload,
): Promise<MutationResult<AchievementDefinition>> {
  try {
    const res = await apiFetch(url, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const err: unknown = await res.json().catch(() => null);
      return { ok: false, error: extractErrorMessage(err) };
    }
    const json: unknown = await res.json();
    const data: unknown =
      typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (!isAchievementDefinition(data)) return { ok: false, error: "Réponse invalide du serveur." };
    return { ok: true, value: data };
  } catch {
    return { ok: false, error: "Erreur réseau." };
  }
}

async function noContent(url: string, body: unknown): Promise<boolean> {
  try {
    const res = await apiFetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    return res.status === 204;
  } catch {
    return false;
  }
}

function extractErrorMessage(v: unknown): string {
  if (typeof v === "object" && v !== null && "error" in v) {
    const err: unknown = v.error;
    if (typeof err === "object" && err !== null && "message" in err && typeof err.message === "string") {
      return err.message;
    }
  }
  return "L'enregistrement a échoué.";
}

function parseOptions(v: unknown): AchievementFormOptions | null {
  if (typeof v !== "object" || v === null) return null;
  if (!("facts" in v) || !Array.isArray(v.facts)) return null;
  if (!("operators" in v) || !Array.isArray(v.operators)) return null;
  if (!("groupOps" in v) || !Array.isArray(v.groupOps)) return null;

  const facts = v.facts.filter(
    (f): f is AchievementFactOption =>
      typeof f === "object" && f !== null && "key" in f && typeof f.key === "string" && "label" in f && typeof f.label === "string",
  );
  const operators = v.operators.filter((o): o is RuleOperator => typeof o === "string");
  const groupOps = v.groupOps.filter((o): o is RuleGroupOp => typeof o === "string");
  const events =
    "events" in v && Array.isArray(v.events)
      ? v.events.filter(
          (e): e is AchievementEventOption =>
            typeof e === "object" && e !== null && "id" in e && typeof e.id === "string" && "title" in e && typeof e.title === "string",
        )
      : [];

  return { facts, operators, groupOps, events };
}

function isRuleNode(v: unknown): v is RuleNode {
  if (typeof v !== "object" || v === null) return false;
  if ("op" in v) {
    return "rules" in v && Array.isArray(v.rules) && v.rules.every(isRuleNode);
  }
  return "fact" in v && typeof v.fact === "string" && "operator" in v && typeof v.operator === "string" && "value" in v && typeof v.value === "number";
}

function isAchievementDefinition(v: unknown): v is AchievementDefinition {
  if (typeof v !== "object" || v === null) return false;
  if (!("id" in v) || typeof v.id !== "string") return false;
  if (!("key" in v) || typeof v.key !== "string") return false;
  if (!("name" in v) || typeof v.name !== "string") return false;
  if (!("description" in v) || typeof v.description !== "string") return false;
  if (!("active" in v) || typeof v.active !== "boolean") return false;
  if (!("position" in v) || typeof v.position !== "number") return false;
  if (!("rule" in v) || !isRuleNode(v.rule) || !("op" in v.rule)) return false;
  return true;
}