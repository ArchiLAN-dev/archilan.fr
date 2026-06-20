"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Save } from "lucide-react";
import { useState } from "react";

import { InfoTooltip } from "@/components/info-tooltip";
import { Switch } from "@/components/switch";

import { sessionConfigHelp } from "./session-config-help";

import {
  COMPATIBILITY_VALUES,
  COUNTDOWN_MODES,
  PLANDO_OPTIONS,
  RELEASE_COLLECT_MODES,
  REMAINING_MODES,
  SPOILER_LEVELS,
  type SessionConfig,
} from "./admin-session-config-api";

// Adapter so the same editor serves the admin endpoints (weekly template / event session)
// and the owner endpoint (private run). Each returns the partial override object, plus the
// resolved profile (the values inherited by unset fields).
export type OverrideAdapter = {
  queryKey: readonly unknown[];
  load: () => Promise<Record<string, unknown>>;
  loadProfile: () => Promise<SessionConfig | null>;
  save: (override: Record<string, unknown>) => Promise<{ ok: true } | { ok: false; error: string }>;
  clear: () => Promise<boolean>;
};

type OverrideValue = string | number | boolean | string[];

type FieldDef =
  | { key: string; label: string; section: string; kind: "select"; options: readonly string[]; labels: Record<string, string>; fallback: string }
  | { key: string; label: string; section: string; kind: "int"; min?: number; max?: number; fallback: number }
  | { key: string; label: string; section: string; kind: "intselect"; options: readonly number[]; labels: Record<number, string>; fallback: number }
  | { key: string; label: string; section: string; kind: "bool"; fallback: boolean }
  | { key: string; label: string; section: string; kind: "text"; fallback: string }
  | { key: string; label: string; section: string; kind: "plando"; fallback: string[] };

const SECTION_ORDER = ["Échanges d'objets", "Indices & score", "Salle & partie", "Génération"] as const;

// A run-specific join password proposed when the override is enabled (admins/owners can edit it).
function randomPassword(): string {
  return crypto.randomUUID().replace(/-/g, "").slice(0, 16);
}

const RC: Record<string, string> = {
  disabled: "Désactivé",
  enabled: "Toujours autorisé",
  goal: "Après l'objectif atteint",
  auto: "Automatique à l'objectif",
  "auto-enabled": "Automatique + manuel",
};
const REM: Record<string, string> = { enabled: "Toujours autorisé", disabled: "Désactivé", goal: "Après l'objectif atteint" };
const CD: Record<string, string> = { enabled: "Toujours autorisé", disabled: "Désactivé", auto: "Auto (< 30 joueurs)" };
const COMPAT: Record<number, string> = { 2: "Casual (2)", 1: "Racing (1)", 0: "Tournoi (0)" };
const SPOIL: Record<number, string> = { 0: "Aucun", 1: "Sans solution", 2: "Avec solution", 3: "Solution + chemins" };

const FIELDS: FieldDef[] = [
  { key: "releaseMode", label: "Don des objets restants (!release)", section: "Échanges d'objets", kind: "select", options: RELEASE_COLLECT_MODES, labels: RC, fallback: "disabled" },
  { key: "collectMode", label: "Récupération des objets (!collect)", section: "Échanges d'objets", kind: "select", options: RELEASE_COLLECT_MODES, labels: RC, fallback: "disabled" },
  { key: "remainingMode", label: "Voir les objets restants (!remaining)", section: "Échanges d'objets", kind: "select", options: REMAINING_MODES, labels: REM, fallback: "goal" },
  { key: "hintCost", label: "Coût d'un indice (%)", section: "Indices & score", kind: "int", min: 0, max: 100, fallback: 10 },
  { key: "locationCheckPoints", label: "Points gagnés par check", section: "Indices & score", kind: "int", min: 0, fallback: 1 },
  { key: "countdownMode", label: "Compte à rebours (!countdown)", section: "Salle & partie", kind: "select", options: COUNTDOWN_MODES, labels: CD, fallback: "auto" },
  { key: "disableItemCheat", label: "Interdire la triche d'objets (!getitem)", section: "Salle & partie", kind: "bool", fallback: true },
  { key: "compatibility", label: "Compatibilité", section: "Salle & partie", kind: "intselect", options: COMPATIBILITY_VALUES, labels: COMPAT, fallback: 2 },
  { key: "autoShutdown", label: "Arrêt auto après inactivité (s)", section: "Salle & partie", kind: "int", min: 0, fallback: 0 },
  { key: "joinPassword", label: "Mot de passe de connexion", section: "Salle & partie", kind: "text", fallback: "" },
  { key: "plandoOptions", label: "Plando autorisé", section: "Génération", kind: "plando", fallback: [] },
  { key: "race", label: "Mode course (ROMs chiffrées)", section: "Génération", kind: "bool", fallback: false },
  { key: "spoiler", label: "Niveau de spoiler", section: "Génération", kind: "intselect", options: SPOILER_LEVELS, labels: SPOIL, fallback: 3 },
];

const ERROR_LABELS: Record<string, string> = {
  network_error: "Erreur réseau, réessaie.",
  update_failed: "Échec de l'enregistrement.",
  forbidden: "Accès refusé.",
  not_found: "Introuvable.",
};

function errorLabel(code: string): string {
  if (code in ERROR_LABELS) return ERROR_LABELS[code];
  if (code.startsWith("invalid_")) return `Valeur invalide (${code}).`;
  return ERROR_LABELS.update_failed;
}

export function SessionConfigOverrideForm({
  adapter,
  scopeLabel,
  lockedKeys = [],
}: {
  adapter: OverrideAdapter;
  scopeLabel: string;
  // Field keys the current scope may not override (e.g. autoShutdown for private-run owners - it is
  // locked to the admin "private" type profile). Hidden entirely; the server is the authoritative gate.
  lockedKeys?: readonly string[];
}) {
  const fields = FIELDS.filter((f) => !lockedKeys.includes(f.key));
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: adapter.queryKey,
    queryFn: () => adapter.load(),
    staleTime: 30_000,
  });
  const { data: profile } = useQuery({
    queryKey: [...adapter.queryKey, "profile"],
    queryFn: () => adapter.loadProfile(),
    staleTime: 30_000,
  });
  const profileValues = profile ? flattenProfile(profile) : null;

  const [draft, setDraft] = useState<Record<string, OverrideValue> | null>(null);
  const [syncedFrom, setSyncedFrom] = useState<Record<string, unknown> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  if (data && data !== syncedFrom) {
    setSyncedFrom(data);
    setDraft(coerce(data));
  }

  const saveMutation = useMutation({
    mutationFn: (override: Record<string, OverrideValue>) => adapter.save(override),
    onSuccess: async (result) => {
      if (result.ok) {
        setError(null);
        setSaved(true);
        await queryClient.invalidateQueries({ queryKey: adapter.queryKey });
      } else {
        setSaved(false);
        setError(errorLabel(result.error));
      }
    },
    onError: () => setError(errorLabel("update_failed")),
  });

  const clearMutation = useMutation({
    mutationFn: () => adapter.clear(),
    onSuccess: async () => {
      setError(null);
      setSaved(true);
      setDraft({});
      await queryClient.invalidateQueries({ queryKey: adapter.queryKey });
    },
  });

  if (isLoading || draft === null) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
      </div>
    );
  }

  const current = draft;

  function setField(key: string, value: OverrideValue): void {
    setSaved(false);
    setDraft((d) => ({ ...(d ?? {}), [key]: value }));
  }

  function toggleField(field: FieldDef, on: boolean): void {
    setSaved(false);
    setDraft((d) => {
      const next = { ...(d ?? {}) };
      if (on) {
        // Propose a fresh random password when overriding the join password.
        next[field.key] = field.key === "joinPassword" ? randomPassword() : field.fallback;
      } else {
        delete next[field.key];
      }
      return next;
    });
  }

  const overriddenCount = fields.filter((f) => f.key in current).length;

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-prose text-xs text-muted-foreground">
          Surcharge le profil pour {scopeLabel}. Active un champ pour le personnaliser&nbsp;; les champs
          laissés inactifs héritent du profil.
        </p>
        <span
          className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${
            overriddenCount > 0 ? "bg-accent/15 text-accent-text" : "bg-surface-2 text-muted-foreground"
          }`}
        >
          {overriddenCount > 0 ? `${overriddenCount} champ${overriddenCount > 1 ? "s" : ""} surchargé${overriddenCount > 1 ? "s" : ""}` : "Aucune surcharge"}
        </span>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
        {SECTION_ORDER.filter((section) => fields.some((f) => f.section === section)).map((section) => (
          <section className="rounded-xl border border-border bg-surface-2/30 p-4" key={section}>
            <h4 className="mb-1 font-heading text-sm font-semibold text-foreground">{section}</h4>
            <div className="divide-y divide-border/50">
              {fields.filter((f) => f.section === section).map((field) => {
                const overridden = field.key in current;
                return (
                  <div className="py-3 first:pt-2 last:pb-0" key={field.key}>
                    <div className="flex items-start gap-2.5">
                      <span className="mt-0.5">
                        <Switch ariaLabel={`Surcharger ${field.label}`} checked={overridden} onChange={(c) => toggleField(field, c)} />
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className={`flex items-center gap-1.5 text-sm leading-snug ${overridden ? "font-medium text-foreground" : "text-muted-foreground"}`}>
                          {field.label}
                          {sessionConfigHelp[field.key] ? (
                            <InfoTooltip label={`Aide : ${field.label}`} text={sessionConfigHelp[field.key]} />
                          ) : null}
                        </p>
                        {overridden ? (
                          <div className="mt-2">
                            <OverrideControl field={field} onChange={(v) => setField(field.key, v)} value={current[field.key]} />
                          </div>
                        ) : (
                          <p className="mt-0.5 text-xs italic text-muted-foreground/70">
                            {profileValues
                              ? `Profil : ${displayValue(field, profileValues[field.key])}`
                              : "hérité du profil"}
                          </p>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </section>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t border-border pt-4">
        <button
          className="inline-flex items-center gap-2 rounded-lg bg-accent px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={saveMutation.isPending}
          onClick={() => saveMutation.mutate(current)}
          type="button"
        >
          {saveMutation.isPending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Save aria-hidden className="size-4" />}
          Enregistrer l&apos;override
        </button>
        <button
          className="rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
          disabled={clearMutation.isPending || overriddenCount === 0}
          onClick={() => clearMutation.mutate()}
          type="button"
        >
          Tout réinitialiser
        </button>
        {saved ? <span className="text-sm text-success">Enregistré.</span> : null}
        {error !== null ? <span className="text-sm text-danger">{error}</span> : null}
      </div>
    </div>
  );
}

function OverrideControl({ field, value, onChange }: { field: FieldDef; value: OverrideValue; onChange: (v: OverrideValue) => void }) {
  if (field.kind === "select") {
    return (
      <select className="h-8 rounded border border-border bg-surface px-2 text-sm text-foreground" onChange={(e) => onChange(e.target.value)} value={typeof value === "string" ? value : field.fallback}>
        {field.options.map((o) => (
          <option key={o} value={o}>{field.labels[o] ?? o}</option>
        ))}
      </select>
    );
  }
  if (field.kind === "intselect") {
    return (
      <select className="h-8 rounded border border-border bg-surface px-2 text-sm text-foreground" onChange={(e) => onChange(Number(e.target.value))} value={typeof value === "number" ? value : field.fallback}>
        {field.options.map((o) => (
          <option key={o} value={o}>{field.labels[o] ?? String(o)}</option>
        ))}
      </select>
    );
  }
  if (field.kind === "int") {
    return (
      <input className="h-8 w-24 rounded border border-border bg-surface px-2 text-sm text-foreground" max={field.max} min={field.min} onChange={(e) => onChange(Number(e.target.value))} type="number" value={typeof value === "number" ? value : field.fallback} />
    );
  }
  if (field.kind === "bool") {
    return (
      <span className="flex items-center gap-1.5 text-sm text-foreground">
        <Switch ariaLabel={field.label} checked={value === true} onChange={onChange} />
        <span className="text-muted-foreground">{value === true ? "activé" : "désactivé"}</span>
      </span>
    );
  }
  if (field.kind === "text") {
    return (
      <input
        className="h-8 w-48 rounded border border-border bg-surface px-2 text-sm text-foreground"
        onChange={(e) => onChange(e.target.value)}
        type="text"
        value={typeof value === "string" ? value : ""}
      />
    );
  }
  // plando
  const selected = Array.isArray(value) ? value : [];
  return (
    <div className="flex flex-wrap gap-2">
      {PLANDO_OPTIONS.map((opt) => (
        <span className="flex items-center gap-1.5 text-sm text-foreground" key={opt}>
          <Switch
            ariaLabel={opt}
            checked={selected.includes(opt)}
            onChange={() => onChange(selected.includes(opt) ? selected.filter((p) => p !== opt) : [...selected, opt])}
          />
          {opt}
        </span>
      ))}
    </div>
  );
}

// Flatten the resolved profile (nested server/generation) into the flat field-keyed shape.
function flattenProfile(c: SessionConfig): Record<string, OverrideValue> {
  return {
    releaseMode: c.server.releaseMode,
    collectMode: c.server.collectMode,
    remainingMode: c.server.remainingMode,
    countdownMode: c.server.countdownMode,
    disableItemCheat: c.server.disableItemCheat,
    hintCost: c.server.hintCost,
    locationCheckPoints: c.server.locationCheckPoints,
    autoShutdown: c.server.autoShutdown,
    compatibility: c.server.compatibility,
    joinPassword: c.server.joinPassword ?? "",
    plandoOptions: c.generation.plandoOptions,
    race: c.generation.race,
    spoiler: c.generation.spoiler,
  };
}

// Human-readable rendering of a field's inherited (profile) value.
function displayValue(field: FieldDef, value: OverrideValue | undefined): string {
  if (value === undefined) return "-";
  switch (field.kind) {
    case "select":
      return field.labels[String(value)] ?? String(value);
    case "intselect":
      return field.labels[Number(value)] ?? String(value);
    case "int":
      return String(value);
    case "bool":
      return value === true ? "activé" : "désactivé";
    case "text":
      return typeof value === "string" && value !== "" ? value : "aléatoire";
    case "plando": {
      const arr = Array.isArray(value) ? value : [];
      return arr.length > 0 ? arr.join(", ") : "aucun";
    }
  }
}

// Coerce the loaded override (unknown JSON) into a typed draft, keeping only known fields.
function coerce(raw: Record<string, unknown>): Record<string, OverrideValue> {
  const out: Record<string, OverrideValue> = {};
  for (const field of FIELDS) {
    if (!(field.key in raw)) continue;
    const v = raw[field.key];
    if ((field.kind === "select" || field.kind === "text") && typeof v === "string") out[field.key] = v;
    else if ((field.kind === "int" || field.kind === "intselect") && typeof v === "number") out[field.key] = v;
    else if (field.kind === "bool" && typeof v === "boolean") out[field.key] = v;
    else if (field.kind === "plando" && Array.isArray(v)) out[field.key] = v.filter((p): p is string => typeof p === "string");
  }
  return out;
}
