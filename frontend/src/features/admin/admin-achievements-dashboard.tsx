"use client";

import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronDown, ChevronUp, Image as ImageIcon, Loader2, Plus, Search, Trash2, Upload, UserPlus, X } from "lucide-react";

import { fetchDirectory, type DirectoryRow } from "@/features/community/community-directory-api";
import {
  createAchievement,
  fetchAchievementDashboard,
  grantAchievement,
  isRuleGroup,
  reorderAchievements,
  revokeAchievement,
  setAchievementActive,
  updateAchievement,
  uploadAchievementImage,
  type AchievementDefinition,
  type AchievementFormOptions,
  type RuleCondition,
  type RuleGroup,
  type RuleGroupOp,
  type RuleNode,
  type RuleOperator,
} from "./admin-achievements-api";
import {
  EVENTS_FACT,
  eventIdOfFact,
  eventScopedFact,
  factLabel,
  isEventScopedFact,
} from "./admin-achievement-event-scope";

const QUERY_KEY = ["admin-achievements"] as const;
const STALE_TIME = 15_000;

const GROUP_OP_LABELS: Record<RuleGroupOp, string> = {
  all: "Toutes les règles (ET)",
  any: "Au moins une règle (OU)",
  none: "Aucune des règles (NON)",
};

const OPERATOR_LABELS: Record<RuleOperator, string> = {
  ">=": "≥",
  ">": ">",
  "=": "=",
  "!=": "≠",
  "<=": "≤",
  "<": "<",
  between: "entre",
};


type EditorState =
  | { mode: "closed" }
  | { mode: "create" }
  | { mode: "edit"; definition: AchievementDefinition };

export function AdminAchievementsDashboard() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: fetchAchievementDashboard,
    staleTime: STALE_TIME,
  });
  const [editor, setEditor] = useState<EditorState>({ mode: "closed" });
  const [busyId, setBusyId] = useState<string | null>(null);
  const [grantingId, setGrantingId] = useState<string | null>(null);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
  }

  async function toggleActive(definition: AchievementDefinition): Promise<void> {
    setBusyId(definition.id);
    await setAchievementActive(definition.id, !definition.active);
    await refresh();
    setBusyId(null);
  }

  async function move(definitions: AchievementDefinition[], index: number, delta: number): Promise<void> {
    const target = index + delta;
    if (target < 0 || target >= definitions.length) return;
    const ordered = [...definitions];
    const [moved] = ordered.splice(index, 1);
    ordered.splice(target, 0, moved);
    setBusyId(definitions[index].id);
    await reorderAchievements(ordered.map((d) => d.id));
    await refresh();
    setBusyId(null);
  }

  if (editor.mode !== "closed" && data) {
    return (
      <AchievementForm
        existingKeys={data.definitions.map((d) => d.key)}
        initial={editor.mode === "edit" ? editor.definition : null}
        onClose={() => setEditor({ mode: "closed" })}
        onSaved={async () => {
          await refresh();
          setEditor({ mode: "closed" });
        }}
        options={data.options}
      />
    );
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="grid gap-1">
          <h1 className="font-heading text-2xl font-bold text-foreground">Succès</h1>
          <p className="text-sm text-muted-foreground">
            Catalogue des succès débloquables. Les règles sont composables ; un succès gagné n’est jamais retiré.
          </p>
        </div>
        <button
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={!data}
          onClick={() => setEditor({ mode: "create" })}
          type="button"
        >
          <Plus aria-hidden className="size-4" /> Nouveau succès
        </button>
      </header>

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || !data ? (
        <p className="text-sm text-muted-foreground">Impossible de charger les succès.</p>
      ) : data.definitions.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          Aucun succès défini pour le moment.
        </p>
      ) : (
        <ul className="grid gap-3" role="list">
          {data.definitions.map((definition, index) => (
            <li key={definition.id}>
              <article className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface p-4">
                <div className="min-w-0 grid gap-1">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold text-foreground">{definition.name}</span>
                    <code className="rounded bg-surface-2 px-1.5 py-0.5 text-xs text-muted-foreground">{definition.key}</code>
                    {!definition.active ? (
                      <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-400">
                        inactif
                      </span>
                    ) : null}
                  </div>
                  <p className="text-xs text-muted-foreground">{summariseRule(definition.rule, data.options)}</p>
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                  <IconButton
                    busy={busyId === definition.id}
                    label="Monter"
                    onClick={() => void move(data.definitions, index, -1)}
                  >
                    <ChevronUp aria-hidden className="size-4" />
                  </IconButton>
                  <IconButton
                    busy={busyId === definition.id}
                    label="Descendre"
                    onClick={() => void move(data.definitions, index, 1)}
                  >
                    <ChevronDown aria-hidden className="size-4" />
                  </IconButton>
                  <button
                    className="inline-flex min-h-9 items-center rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
                    disabled={busyId === definition.id}
                    onClick={() => void toggleActive(definition)}
                    type="button"
                  >
                    {definition.active ? "Désactiver" : "Activer"}
                  </button>
                  <button
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                    onClick={() => setGrantingId(grantingId === definition.id ? null : definition.id)}
                    type="button"
                  >
                    <UserPlus aria-hidden className="size-4" /> Attribuer
                  </button>
                  <button
                    className="inline-flex min-h-9 items-center rounded-lg border border-accent px-3 text-sm font-semibold text-accent-text transition-colors hover:bg-accent hover:text-white"
                    onClick={() => setEditor({ mode: "edit", definition })}
                    type="button"
                  >
                    Modifier
                  </button>
                </div>
              </article>
              {grantingId === definition.id ? (
                <GrantPanel
                  definitionId={definition.id}
                  definitionName={definition.name}
                  onClose={() => setGrantingId(null)}
                />
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function IconButton({
  children,
  busy,
  label,
  onClick,
}: {
  children: React.ReactNode;
  busy: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      aria-label={label}
      className="inline-flex size-9 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
      disabled={busy}
      onClick={onClick}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}

// ── Manual grant panel (story 30.34) ────────────────────────────────────────────

function GrantPanel({
  definitionId,
  definitionName,
  onClose,
}: {
  definitionId: string;
  definitionName: string;
  onClose: () => void;
}) {
  const [search, setSearch] = useState("");
  const [rows, setRows] = useState<DirectoryRow[]>([]);
  const [searching, setSearching] = useState(false);
  const [searched, setSearched] = useState(false);
  const [busySlug, setBusySlug] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  async function runSearch(): Promise<void> {
    setSearching(true);
    setMessage(null);
    const result = await fetchDirectory({ mode: "top", search, page: 1 });
    setRows(result?.rows ?? []);
    setSearched(true);
    setSearching(false);
  }

  async function act(action: "grant" | "revoke", row: DirectoryRow): Promise<void> {
    const name = row.displayName ?? row.slug;
    setBusySlug(row.slug);
    const ok =
      action === "grant"
        ? await grantAchievement(definitionId, row.slug)
        : await revokeAchievement(definitionId, row.slug);
    setBusySlug(null);
    setMessage(
      ok
        ? action === "grant"
          ? `« ${definitionName} » attribué à ${name}.`
          : `« ${definitionName} » retiré de ${name}.`
        : "L’opération a échoué.",
    );
  }

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button aria-label="Fermer" className="absolute inset-0 cursor-default bg-black/60" onClick={onClose} type="button" />
      <div className="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-xl">
        <div className="flex items-center justify-between gap-3 border-b border-border p-4">
          <h3 className="min-w-0 truncate font-heading text-base font-semibold text-foreground">
            Attribuer « {definitionName} » à un joueur
          </h3>
          <button
            aria-label="Fermer"
            className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
            onClick={onClose}
            type="button"
          >
            <X aria-hidden className="size-4" />
          </button>
        </div>

        <div className="grid gap-3 overflow-y-auto p-4">
          <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          void runSearch();
        }}
      >
        <input
          className="min-h-9 flex-1 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher un joueur (pseudo)…"
          value={search}
        />
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          type="submit"
        >
          {searching ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Search aria-hidden className="size-4" />}
          Rechercher
        </button>
      </form>

      {rows.length > 0 ? (
        <ul className="grid gap-1.5">
          {rows.map((row) => (
            <li
              className="flex items-center justify-between gap-2 rounded border border-border bg-surface px-3 py-2"
              key={row.slug}
            >
              <span className="min-w-0 truncate text-sm text-foreground">
                {row.displayName ?? row.slug} <span className="text-xs text-muted-foreground">@{row.slug}</span>
              </span>
              <span className="flex shrink-0 items-center gap-1.5">
                <button
                  className="rounded border border-accent px-2 py-1 text-xs font-semibold text-accent-text transition-colors hover:bg-accent hover:text-white disabled:opacity-50"
                  disabled={busySlug === row.slug}
                  onClick={() => void act("grant", row)}
                  type="button"
                >
                  Attribuer
                </button>
                <button
                  className="rounded border border-border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:border-red-400 hover:text-red-400 disabled:opacity-50"
                  disabled={busySlug === row.slug}
                  onClick={() => void act("revoke", row)}
                  type="button"
                >
                  Retirer
                </button>
              </span>
            </li>
          ))}
        </ul>
      ) : searched && !searching ? (
        <p className="text-xs text-muted-foreground">Aucun joueur trouvé.</p>
      ) : null}

          {message ? <p className="text-xs text-accent-text">{message}</p> : null}
        </div>
      </div>
    </div>
  );
}

// ── Form ───────────────────────────────────────────────────────────────────────

const KEY_PATTERN = /^[a-z0-9_]{1,64}$/;

function AchievementForm({
  initial,
  options,
  existingKeys,
  onClose,
  onSaved,
}: {
  initial: AchievementDefinition | null;
  options: AchievementFormOptions;
  existingKeys: string[];
  onClose: () => void;
  onSaved: () => Promise<void>;
}) {
  const [key, setKey] = useState(initial?.key ?? "");
  const [name, setName] = useState(initial?.name ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [rule, setRule] = useState<RuleGroup>(initial?.rule ?? newGroup(options));
  const [imageKey, setImageKey] = useState<string | null>(initial?.customImageKey ?? null);
  const [imageUrl, setImageUrl] = useState<string | null>(initial?.customImageUrl ?? null);
  const [uploadingImage, setUploadingImage] = useState(false);
  const [imageError, setImageError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const isEdit = initial !== null;
  const keyValid = isEdit || KEY_PATTERN.test(key);
  const keyDuplicate = !isEdit && existingKeys.includes(key);
  const canSubmit = keyValid && !keyDuplicate && name.trim() !== "" && ruleIsComplete(rule) && !saving;

  async function handlePickImage(file: File): Promise<void> {
    setUploadingImage(true);
    setImageError(null);
    const result = await uploadAchievementImage(file);
    setUploadingImage(false);
    if (result === null) {
      setImageError("L'upload a échoué (JPEG, PNG ou WebP, 5 Mo max).");
      return;
    }
    setImageKey(result.key);
    setImageUrl(result.imageUrl);
  }

  async function submit(): Promise<void> {
    setSaving(true);
    setError(null);
    const result = isEdit
      ? await updateAchievement(initial.id, { name: name.trim(), description: description.trim(), rule, customImageKey: imageKey })
      : await createAchievement({ key, name: name.trim(), description: description.trim(), rule, customImageKey: imageKey });
    setSaving(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    await onSaved();
  }

  return (
    <section className="mx-auto grid w-full max-w-3xl gap-6 p-6 md:p-8">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">
          {isEdit ? "Modifier un succès" : "Nouveau succès"}
        </h1>
        <p className="text-sm text-muted-foreground">
          Le déblocage est évalué de manière monotone : une règle assouplie débloque rétroactivement, mais un
          succès déjà obtenu n’est jamais retiré.
        </p>
      </header>

      <div className="grid gap-4">
        <Field label="Clé (immuable)">
          <input
            className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent disabled:opacity-60"
            disabled={isEdit}
            onChange={(e) => setKey(e.target.value)}
            placeholder="ex. night_owl"
            value={key}
          />
          {!isEdit && key !== "" && !keyValid ? (
            <span className="text-xs text-red-400">Minuscules, chiffres et underscore uniquement.</span>
          ) : null}
          {keyDuplicate ? <span className="text-xs text-red-400">Cette clé existe déjà.</span> : null}
        </Field>

        <Field label="Nom">
          <input
            className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent"
            onChange={(e) => setName(e.target.value)}
            placeholder="ex. Oiseau de nuit"
            value={name}
          />
        </Field>

        <Field label="Description">
          <textarea
            className="min-h-20 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Ce que le joueur doit accomplir."
            value={description}
          />
        </Field>

        <Field label="Image (optionnel, remplace le trophée)">
          <div className="flex flex-wrap items-center gap-3">
            {imageUrl ? (
              // eslint-disable-next-line @next/next/no-img-element -- remote presigned image, not a local asset
              <img alt="" className="size-12 rounded-lg border border-border object-cover" src={imageUrl} />
            ) : (
              <span className="flex size-12 items-center justify-center rounded-lg border border-dashed border-border text-muted-foreground">
                <ImageIcon aria-hidden className="size-5" />
              </span>
            )}
            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground">
              {uploadingImage ? (
                <Loader2 aria-hidden className="size-4 animate-spin" />
              ) : (
                <Upload aria-hidden className="size-4" />
              )}
              {imageUrl ? "Remplacer" : "Choisir une image"}
              <input
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) void handlePickImage(file);
                  e.target.value = "";
                }}
                type="file"
              />
            </label>
            {imageUrl ? (
              <button
                className="text-sm text-muted-foreground transition-colors hover:text-red-400"
                onClick={() => {
                  setImageKey(null);
                  setImageUrl(null);
                }}
                type="button"
              >
                Retirer
              </button>
            ) : null}
          </div>
          {imageError ? <span className="text-xs text-red-400">{imageError}</span> : null}
        </Field>

        <div className="grid gap-2">
          <span className="text-sm font-semibold text-foreground">Règle de déblocage</span>
          <RuleGroupEditor group={rule} onChange={setRule} options={options} root />
          {!ruleIsComplete(rule) ? (
            <span className="text-xs text-amber-400">Chaque groupe doit contenir au moins une règle.</span>
          ) : null}
        </div>
      </div>

      {error ? (
        <p className="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">{error}</p>
      ) : null}

      <div className="flex justify-end gap-3">
        <button
          className="inline-flex min-h-10 items-center rounded-lg border border-border px-4 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          Annuler
        </button>
        <button
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={!canSubmit}
          onClick={() => void submit()}
          type="button"
        >
          {saving ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Enregistrer
        </button>
      </div>
    </section>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="grid gap-1.5">
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
    </label>
  );
}

// ── Recursive rule builder ──────────────────────────────────────────────────────

function RuleGroupEditor({
  group,
  options,
  onChange,
  root = false,
}: {
  group: RuleGroup;
  options: AchievementFormOptions;
  onChange: (next: RuleGroup) => void;
  root?: boolean;
}) {
  function setOp(op: RuleGroupOp): void {
    onChange({ ...group, op });
  }

  function setChild(index: number, node: RuleNode): void {
    const rules = group.rules.map((r, i) => (i === index ? node : r));
    onChange({ ...group, rules });
  }

  function removeChild(index: number): void {
    onChange({ ...group, rules: group.rules.filter((_, i) => i !== index) });
  }

  function addCondition(): void {
    onChange({ ...group, rules: [...group.rules, newCondition(options)] });
  }

  function addGroup(): void {
    onChange({ ...group, rules: [...group.rules, newGroup(options)] });
  }

  return (
    <div className={`grid gap-3 rounded-lg border p-3 ${root ? "border-border bg-surface" : "border-border/70 bg-surface-2"}`}>
      <div className="flex items-center gap-2">
        <select
          aria-label="Opérateur du groupe"
          className="min-h-9 rounded-lg border border-border bg-surface px-2 text-sm text-foreground outline-none focus:border-accent"
          onChange={(e) => setOp(asGroupOp(e.target.value, options))}
          value={group.op}
        >
          {options.groupOps.map((op) => (
            <option key={op} value={op}>
              {GROUP_OP_LABELS[op] ?? op}
            </option>
          ))}
        </select>
        <span className="text-xs text-muted-foreground">doivent être satisfaites</span>
      </div>

      <div className="grid gap-2">
        {group.rules.map((node, index) => (
          <div className="flex items-start gap-2" key={index}>
            <div className="flex-1">
              {isRuleGroup(node) ? (
                <RuleGroupEditor group={node} onChange={(n) => setChild(index, n)} options={options} />
              ) : (
                <ConditionEditor
                  condition={node}
                  onChange={(n) => setChild(index, n)}
                  options={options}
                />
              )}
            </div>
            <button
              aria-label="Supprimer cette règle"
              className="mt-1 inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-red-400 hover:text-red-400"
              onClick={() => removeChild(index)}
              title="Supprimer"
              type="button"
            >
              <Trash2 aria-hidden className="size-4" />
            </button>
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <button
          className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          onClick={addCondition}
          type="button"
        >
          <Plus aria-hidden className="size-3.5" /> Condition
        </button>
        <button
          className="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          onClick={addGroup}
          type="button"
        >
          <Plus aria-hidden className="size-3.5" /> Sous-groupe
        </button>
      </div>
    </div>
  );
}

function ConditionEditor({
  condition,
  options,
  onChange,
}: {
  condition: RuleCondition;
  options: AchievementFormOptions;
  onChange: (next: RuleCondition) => void;
}) {
  const eventScoped = isEventScopedFact(condition.fact);
  const eventId = eventIdOfFact(condition.fact);
  const eventMissing = eventId !== null && !options.events.some((e) => e.id === eventId);

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border/70 bg-surface px-2 py-2">
      <select
        aria-label="Métrique"
        className="min-h-9 rounded-lg border border-border bg-surface-2 px-2 text-sm text-foreground outline-none focus:border-accent"
        onChange={(e) => onChange({ ...condition, fact: e.target.value })}
        value={eventScoped ? EVENTS_FACT : condition.fact}
      >
        {options.facts.map((f) => (
          <option key={f.key} value={f.key}>
            {f.label}
          </option>
        ))}
      </select>

      {eventScoped ? (
        <select
          aria-label="Événement"
          className="min-h-9 rounded-lg border border-border bg-surface-2 px-2 text-sm text-foreground outline-none focus:border-accent"
          onChange={(e) => onChange({ ...condition, fact: eventScopedFact(e.target.value) })}
          value={eventId ?? ""}
        >
          <option value="">Tous les événements</option>
          {options.events.map((ev) => (
            <option key={ev.id} value={ev.id}>
              {ev.title}
            </option>
          ))}
          {eventMissing && eventId !== null ? (
            <option value={eventId}>(événement supprimé)</option>
          ) : null}
        </select>
      ) : null}

      <select
        aria-label="Opérateur"
        className="min-h-9 rounded-lg border border-border bg-surface-2 px-2 text-sm text-foreground outline-none focus:border-accent"
        onChange={(e) => onChange(withOperator(condition, asOperator(e.target.value, options)))}
        value={condition.operator}
      >
        {options.operators.map((op) => (
          <option key={op} value={op}>
            {OPERATOR_LABELS[op] ?? op}
          </option>
        ))}
      </select>

      <input
        aria-label="Valeur"
        className="min-h-9 w-24 rounded-lg border border-border bg-surface-2 px-2 text-sm text-foreground outline-none focus:border-accent"
        inputMode="numeric"
        onChange={(e) => onChange({ ...condition, value: toInt(e.target.value) })}
        type="number"
        value={condition.value}
      />

      {condition.operator === "between" ? (
        <>
          <span className="text-xs text-muted-foreground">et</span>
          <input
            aria-label="Valeur supérieure"
            className="min-h-9 w-24 rounded-lg border border-border bg-surface-2 px-2 text-sm text-foreground outline-none focus:border-accent"
            inputMode="numeric"
            onChange={(e) => onChange({ ...condition, value2: toInt(e.target.value) })}
            type="number"
            value={condition.value2 ?? condition.value}
          />
        </>
      ) : null}
    </div>
  );
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function newGroup(options: AchievementFormOptions): RuleGroup {
  return { op: options.groupOps[0] ?? "all", rules: [] };
}

function newCondition(options: AchievementFormOptions): RuleCondition {
  return { fact: options.facts[0]?.key ?? "", operator: options.operators[0] ?? ">=", value: 1 };
}

function withOperator(condition: RuleCondition, operator: RuleOperator): RuleCondition {
  if (operator === "between") {
    return { ...condition, operator, value2: condition.value2 ?? condition.value };
  }
  // Drop value2 entirely for non-range operators.
  return { fact: condition.fact, operator, value: condition.value };
}

function toInt(raw: string): number {
  const parsed = Number.parseInt(raw, 10);
  return Number.isNaN(parsed) ? 0 : parsed;
}

function asGroupOp(value: string, options: AchievementFormOptions): RuleGroupOp {
  return options.groupOps.find((op) => op === value) ?? options.groupOps[0] ?? "all";
}

function asOperator(value: string, options: AchievementFormOptions): RuleOperator {
  return options.operators.find((op) => op === value) ?? options.operators[0] ?? ">=";
}

function ruleIsComplete(node: RuleNode): boolean {
  if (!isRuleGroup(node)) return true;
  if (node.rules.length === 0) return false;
  return node.rules.every(ruleIsComplete);
}

function summariseRule(node: RuleNode, options: AchievementFormOptions, depth = 0): string {
  if (!isRuleGroup(node)) {
    const op = OPERATOR_LABELS[node.operator] ?? node.operator;
    const label = factLabel(node.fact, options);
    if (node.operator === "between") {
      return `${label} ${op} ${node.value}–${node.value2 ?? node.value}`;
    }
    return `${label} ${op} ${node.value}`;
  }
  if (node.rules.length === 0) return "(vide)";
  const joiner = node.op === "all" ? " ET " : node.op === "any" ? " OU " : " NI ";
  const inner = node.rules.map((r) => summariseRule(r, options, depth + 1)).join(joiner);
  const body = node.op === "none" ? `NON(${inner})` : inner;
  return depth === 0 ? body : `(${body})`;
}
