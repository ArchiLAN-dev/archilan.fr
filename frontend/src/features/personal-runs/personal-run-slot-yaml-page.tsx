"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, ArrowLeft, Check, CheckCircle, FolderDown, Loader2, Pencil, Plus, Save, Trash2, X } from "lucide-react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { YamlOptionEditor, type YamlEditorHandle } from "@/features/events/yaml-option-editor";
import { YamlOptionsView } from "@/components/yaml/yaml-options-view";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMyGameSelection } from "./personal-runs-api";
import {
  createYamlTemplate,
  deleteYamlTemplate,
  fetchYamlTemplates,
  updateYamlTemplate,
} from "./yaml-templates-api";

// ─── Types ────────────────────────────────────────────────────────────────────

type SlotSave =
  | { kind: "idle" }
  | { kind: "saving" }
  | { kind: "saved" }
  | { kind: "error"; message: string };

function templateErrorMessage(code: string): string {
  switch (code) {
    case "template_name_taken":
      return "Un template porte déjà ce nom pour ce jeu.";
    case "invalid_yaml":
      return "La configuration YAML est invalide.";
    case "name_required":
      return "Le nom est requis.";
    case "name_too_long":
      return "Nom trop long (80 caractères max).";
    case "yaml_required":
      return "La configuration est vide.";
    default:
      return "Action impossible.";
  }
}

// ─── Main page ────────────────────────────────────────────────────────────────

export function PersonalRunSlotYamlPage({
  params,
}: {
  params: Promise<{ runId: string; slotId: string }>;
}) {
  const { runId, slotId } = use(params);

  const editorRef = useRef<YamlEditorHandle>(null);
  // The YAML currently in the editor (template mode mirrors edits here).
  const [currentYaml, setCurrentYaml] = useState("");
  // The last value persisted to the slot - drives the "unsaved" indicator.
  const [savedYaml, setSavedYaml] = useState("");
  // The YAML loaded into the editor; bumping `editorKey` remounts it (used on "apply template").
  const [editorYaml, setEditorYaml] = useState<string | null>(null);
  const [editorKey, setEditorKey] = useState(0);
  const [slotSave, setSlotSave] = useState<SlotSave>({ kind: "idle" });
  // Which slot the editor state was seeded for - the editor hydrates exactly once per slot, so a
  // background refetch of the shared game-selection query can never clobber in-progress edits.
  const [hydratedSlotId, setHydratedSlotId] = useState<string | null>(null);

  // fetchMyGameSelection never throws (401/403, 404, server errors and network failures are all
  // encoded in the result's `kind`), so the query never errors and - like the old effect - never
  // retries. Same key as the game-selection page: both read the same endpoint and share the cache.
  const selectionQuery = useQuery({
    queryKey: ["personal-run-game-selection", runId],
    queryFn: () => fetchMyGameSelection(runId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const result = selectionQuery.data;
  const resultKind = result?.kind;
  const selection = result?.kind === "data" ? result.data : null;
  const slot = selection?.slots.find((s) => s.slotId === slotId) ?? null;
  const game = slot === null ? null : selection?.availableGames.find((g) => g.id === slot.gameId) ?? null;

  // 401/403: full-page login redirect, exactly as the old effect did.
  useEffect(() => {
    if (resultKind === "unauthorized") {
      window.location.href = `/connexion?returnTo=/runs/${runId}/slots/${slotId}`;
    }
  }, [resultKind, runId, slotId]);

  // One-shot editor hydration from the fetched slot (YamlOptionEditor reads its props at mount, so
  // the editor is only rendered once these seeds are in place - see the hydration gate below).
  useEffect(() => {
    if (slot === null || game === null || hydratedSlotId === slot.slotId) return;
    const seed = slot.playerYaml ?? game.defaultYaml ?? "";
    /* eslint-disable react-hooks/set-state-in-effect -- sanctioned one-shot seed-into-form hydration (guarded by hydratedSlotId); the editor dirty-state stays local UI state per AC-ST2 */
    setEditorYaml(slot.playerYaml);
    setCurrentYaml(seed);
    setSavedYaml(seed);
    setHydratedSlotId(slot.slotId);
    /* eslint-enable react-hooks/set-state-in-effect */
  }, [slot, game, hydratedSlotId]);

  const applyTemplate = useCallback((yaml: string) => {
    setEditorYaml(yaml);
    setCurrentYaml(yaml);
    setEditorKey((k) => k + 1);
    setSlotSave({ kind: "idle" });
  }, []);

  async function handleSaveSlot() {
    if (!editorRef.current?.validate()) return;
    setSlotSave({ kind: "saving" });
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/participants/me/slots/${slotId}/yaml`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ playerYaml: currentYaml }),
      });
      if (!res.ok) {
        setSlotSave({ kind: "error", message: "Impossible de sauvegarder la configuration." });
        return;
      }
      setSavedYaml(currentYaml);
      setSlotSave({ kind: "saved" });
    } catch {
      setSlotSave({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (selectionQuery.isPending || resultKind === "unauthorized") {
    return (
      <div aria-hidden className="mx-auto max-w-2xl grid gap-6">
        <div className="h-8 w-48 animate-pulse rounded bg-surface" />
        <div className="h-64 animate-pulse rounded-lg border border-border bg-surface" />
      </div>
    );
  }

  if (resultKind === "not_found" || (selection !== null && (slot === null || game === null))) {
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Slot introuvable</p>
        <p className="text-sm text-muted-foreground">Ce slot n&apos;existe pas ou n&apos;est plus accessible.</p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={`/runs/${runId}/jeux`}>
          Retour à la sélection de jeux
        </Link>
      </div>
    );
  }

  if (selection === null || slot === null || game === null) {
    const message =
      result?.kind === "error" && result.reason === "network"
        ? "Impossible de contacter l'API."
        : "Impossible de charger les informations du slot.";
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{message}</p>
      </div>
    );
  }

  // Wait for the one-shot hydration effect before mounting the editor (it reads its props at mount).
  if (hydratedSlotId !== slot.slotId) {
    return (
      <div aria-hidden className="mx-auto max-w-2xl grid gap-6">
        <div className="h-8 w-48 animate-pulse rounded bg-surface" />
        <div className="h-64 animate-pulse rounded-lg border border-border bg-surface" />
      </div>
    );
  }

  const data = { slot, game, locked: selection.status !== "draft" };

  if (!data.game.isApworldReady) {
    return (
      <div className="mx-auto max-w-2xl grid gap-6">
        <header className="grid gap-2">
          <Link
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
            href={`/runs/${runId}/jeux`}
          >
            <ArrowLeft aria-hidden className="size-3.5" />
            Retour à la sélection
          </Link>
          <h1 className="font-heading text-2xl font-bold text-foreground">{data.slot.gameName}</h1>
        </header>
        <div className="rounded-lg border border-border bg-surface p-6">
          <p className="text-sm text-muted-foreground">
            Ce jeu n&apos;a pas encore de fichier .apworld configuré. La configuration YAML n&apos;est pas encore disponible.
          </p>
        </div>
      </div>
    );
  }

  if (data.locked) {
    return (
      <div className="mx-auto max-w-2xl grid gap-6">
        <header className="grid gap-2">
          <Link
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
            href={`/runs/${runId}/jeux`}
          >
            <ArrowLeft aria-hidden className="size-3.5" />
            Retour à la sélection
          </Link>
          <h1 className="font-heading text-2xl font-bold text-foreground">{data.slot.gameName}</h1>
        </header>
        <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm text-foreground">
          <AlertCircle aria-hidden className="mt-0.5 size-4 shrink-0 text-warning" />
          <p>
            La partie a déjà été générée : cette configuration n&apos;est plus modifiable (la reprise
            rejoue toujours la partie existante).
          </p>
        </div>
        <div className="rounded-lg border border-border bg-surface p-5">
          <YamlOptionsView
            gameName={data.slot.gameName}
            yamlConfig={data.slot.playerYaml ?? data.game.defaultYaml ?? ""}
          />
        </div>
      </div>
    );
  }

  const dirty = currentYaml !== savedYaml;

  return (
    <div className="mx-auto max-w-2xl grid gap-6">
      <header className="grid gap-2">
        <Link
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
          href={`/runs/${runId}/jeux`}
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour à la sélection
        </Link>
        <h1 className="font-heading text-2xl font-bold text-foreground">{data.slot.gameName}</h1>
        {dirty && (
          <p className="text-xs text-[color:var(--color-accent-warm)]">Modifications non sauvegardées</p>
        )}
      </header>

      <TemplatesPanel
        currentYaml={currentYaml}
        gameId={data.game.id}
        onApply={applyTemplate}
        validate={() => editorRef.current?.validate() ?? false}
      />

      <YamlOptionEditor
        key={editorKey}
        ref={editorRef}
        defaultYaml={data.game.defaultYaml}
        locationNames={data.game.locationNames ?? null}
        onChange={setCurrentYaml}
        optionTypes={data.game.optionTypes}
        playerYaml={editorYaml}
      />

      <div className="grid gap-2">
        <button
          className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50 sm:w-fit"
          disabled={slotSave.kind === "saving"}
          onClick={() => { void handleSaveSlot(); }}
          type="button"
        >
          <Save aria-hidden className="size-4" />
          {slotSave.kind === "saving" ? "Sauvegarde…" : "Sauvegarder la configuration"}
        </button>
        {slotSave.kind === "saved" && (
          <span className="inline-flex items-center gap-1.5 text-sm text-success">
            <CheckCircle aria-hidden className="size-4" />
            Sauvegardé
          </span>
        )}
        {slotSave.kind === "error" && (
          <span className="inline-flex items-center gap-1.5 text-sm text-[color:var(--color-danger)]">
            <AlertCircle aria-hidden className="size-4" />
            {slotSave.message}
          </span>
        )}
      </div>
    </div>
  );
}

// ─── Templates panel ───────────────────────────────────────────────────────────

function TemplatesPanel({
  gameId,
  currentYaml,
  validate,
  onApply,
}: {
  gameId: string;
  currentYaml: string;
  validate: () => boolean;
  onApply: (yaml: string) => void;
}) {
  const queryClient = useQueryClient();
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [renamingId, setRenamingId] = useState<string | null>(null);
  const [renameValue, setRenameValue] = useState("");

  // fetchYamlTemplates never throws (failures come back as null, rendered as an empty list like
  // before), so the query never errors and - like the old effect - never retries.
  const templatesQuery = useQuery({
    queryKey: ["yaml-templates", gameId],
    queryFn: () => fetchYamlTemplates(gameId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  // Same tri-state as the old local state: null while loading, [] on fetch failure.
  const templates = templatesQuery.isPending ? null : templatesQuery.data ?? [];

  const reload = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ["yaml-templates", gameId] });
  }, [queryClient, gameId]);

  async function handleCreate() {
    setError(null);
    if (!name.trim()) {
      setError("Donne un nom au template.");
      return;
    }
    if (!validate()) {
      setError("Corrige la configuration avant d'enregistrer.");
      return;
    }
    setBusy(true);
    const res = await createYamlTemplate({ gameId, name: name.trim(), yaml: currentYaml });
    setBusy(false);
    if (res?.ok) {
      setName("");
      void reload();
    } else if (res) {
      setError(templateErrorMessage(res.code));
    } else {
      setError("Impossible d'enregistrer le template.");
    }
  }

  async function handleOverwrite(id: string) {
    if (!validate()) return;
    setBusy(true);
    const res = await updateYamlTemplate(id, { yaml: currentYaml });
    setBusy(false);
    if (res?.ok) void reload();
    else if (res) setError(templateErrorMessage(res.code));
  }

  async function handleRename(id: string) {
    setError(null);
    const res = await updateYamlTemplate(id, { name: renameValue.trim() });
    if (res?.ok) {
      setRenamingId(null);
      void reload();
    } else if (res) {
      setError(templateErrorMessage(res.code));
    }
  }

  async function handleDelete(id: string) {
    if (await deleteYamlTemplate(id)) void reload();
  }

  return (
    <section className="grid gap-3 rounded-lg border border-border p-4">
      <h2 className="font-heading text-sm font-semibold text-foreground">Mes templates YAML</h2>

      <div className="flex flex-wrap items-center gap-2">
        <input
          aria-label="Nom du template"
          className="min-h-9 min-w-0 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          maxLength={80}
          onChange={(e) => { setName(e.target.value); setError(null); }}
          placeholder="Nom du template…"
          type="text"
          value={name}
        />
        <button
          className="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50"
          disabled={busy}
          onClick={() => { void handleCreate(); }}
          type="button"
        >
          <Plus aria-hidden className="size-3.5" />
          Enregistrer la config actuelle
        </button>
      </div>

      {error && <p className="text-xs text-[color:var(--color-danger)]">{error}</p>}

      {templates === null ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <Loader2 aria-hidden className="size-3.5 animate-spin" />
          Chargement…
        </p>
      ) : templates.length === 0 ? (
        <p className="text-xs text-muted-foreground">Aucun template pour ce jeu.</p>
      ) : (
        <ul className="grid gap-1.5" role="list">
          {templates.map((template) => (
            <li
              className="flex flex-wrap items-center justify-between gap-2 rounded border border-border bg-background px-3 py-2"
              key={template.id}
            >
              {renamingId === template.id ? (
                <div className="flex min-w-0 flex-1 items-center gap-1.5">
                  <input
                    aria-label={`Renommer ${template.name}`}
                    className="min-h-8 min-w-0 flex-1 rounded border border-border bg-surface px-2 text-sm text-foreground outline-none focus:border-accent"
                    maxLength={80}
                    onChange={(e) => setRenameValue(e.target.value)}
                    value={renameValue}
                  />
                  <button
                    aria-label="Confirmer le renommage"
                    className="inline-flex size-7 items-center justify-center rounded text-success hover:bg-success/10"
                    onClick={() => { void handleRename(template.id); }}
                    type="button"
                  >
                    <Check aria-hidden className="size-3.5" />
                  </button>
                  <button
                    aria-label="Annuler"
                    className="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-surface hover:text-foreground"
                    onClick={() => setRenamingId(null)}
                    type="button"
                  >
                    <X aria-hidden className="size-3.5" />
                  </button>
                </div>
              ) : (
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{template.name}</span>
              )}

              {renamingId !== template.id && (
                <div className="flex shrink-0 items-center gap-1">
                  <button
                    className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-foreground transition-colors hover:border-accent"
                    onClick={() => onApply(template.yaml)}
                    type="button"
                  >
                    <FolderDown aria-hidden className="size-3.5" />
                    Appliquer
                  </button>
                  <button
                    aria-label={`Écraser ${template.name} avec la config actuelle`}
                    className="inline-flex size-8 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-surface hover:text-foreground disabled:opacity-50"
                    disabled={busy}
                    onClick={() => { void handleOverwrite(template.id); }}
                    title="Écraser avec la config actuelle"
                    type="button"
                  >
                    <Save aria-hidden className="size-3.5" />
                  </button>
                  <button
                    aria-label={`Renommer ${template.name}`}
                    className="inline-flex size-8 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
                    onClick={() => { setRenamingId(template.id); setRenameValue(template.name); }}
                    type="button"
                  >
                    <Pencil aria-hidden className="size-3.5" />
                  </button>
                  <button
                    aria-label={`Supprimer ${template.name}`}
                    className="inline-flex size-8 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)]"
                    onClick={() => { void handleDelete(template.id); }}
                    type="button"
                  >
                    <Trash2 aria-hidden className="size-3.5" />
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
