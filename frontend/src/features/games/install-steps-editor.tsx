"use client";

import { ChevronDown, ChevronUp, ImagePlus, Loader2, Plus, X } from "lucide-react";
import { useState } from "react";

import { uploadTutorialImage } from "./tutorial-image-api";
import { MarkdownEditor } from "@/components/markdown/markdown-editor";
import { INSTALL_STEP_DESCRIPTION_MAX } from "@/lib/content-limits";

export type InstallStepType = "acquire" | "apworld" | "client" | "yaml" | "connect" | "note";
export type InstallStep = {
  type: InstallStepType;
  title: string;
  description: string;
  videoUrl?: string | null;
};

/**
 * Kept as the single save-time projection of the editor's steps (story 31.1). Since story 31.11 a
 * step carries no link list and no image field - both live in the markdown description - so there is
 * nothing left to strip and this is a pass-through, retained so callers keep one place to change.
 */
export function serializeStepsForSave(steps: InstallStep[]): InstallStep[] {
  return steps.map((step) => ({ ...step }));
}

/**
 * Controlled, reusable editor for an ordered list of install-tutorial steps (story 31.1).
 * Used by the admin game editor and (later, 31.6) the community submission form.
 */
export function InstallStepsEditor({
  steps,
  onChange,
}: {
  steps: InstallStep[];
  onChange: (steps: InstallStep[]) => void;
}) {
  function updateStep(index: number, patch: Partial<InstallStep>) {
    onChange(steps.map((step, i) => (i === index ? { ...step, ...patch } : step)));
  }

  function moveStep(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= steps.length) return;
    const next = [...steps];
    const moved = next[index];
    next[index] = next[target];
    next[target] = moved;
    onChange(next);
  }

  function addStep() {
    onChange([...steps, { type: "note", title: "", description: "" }]);
  }


  return (
    <div className="grid gap-4">
      {steps.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune étape. Ajoute une étape ou génère un brouillon.</p>
      ) : null}

      {/* Index keys are the least-bad option here: steps have no natural id (type/title are all
          editable), content-based keys would remount inputs on every keystroke (focus loss), and
          the list is owned by the parent (controlled), so a local id store cannot stay in sync
          with external replacements such as draft generation. Inputs are fully controlled, so
          values stay correct across reorder/removal. */}
      {steps.map((step, index) => (
        <div className="grid gap-3 rounded-lg border border-border bg-surface p-4" key={index}>
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
              Étape {index + 1}
            </span>

            <div className="flex items-center gap-1">
              <IconButton label="Monter" onClick={() => moveStep(index, -1)} disabled={index === 0}>
                <ChevronUp aria-hidden="true" className="size-4" />
              </IconButton>
              <IconButton label="Descendre" onClick={() => moveStep(index, 1)} disabled={index === steps.length - 1}>
                <ChevronDown aria-hidden="true" className="size-4" />
              </IconButton>
              <IconButton label="Supprimer l'étape" onClick={() => onChange(steps.filter((_, i) => i !== index))}>
                <X aria-hidden="true" className="size-4" />
              </IconButton>
            </div>
          </div>

          <input
            aria-label={`Titre de l'étape ${index + 1}`}
            className="min-h-9 w-full rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
            onChange={(e) => updateStep(index, { title: e.target.value })}
            placeholder="Titre de l'étape"
            type="text"
            value={step.title}
          />

          <MarkdownEditor
            maxLength={INSTALL_STEP_DESCRIPTION_MAX}
            onChange={(v: string) => updateStep(index, { description: v })}
            placeholder="Description (markdown supporté)"
            rows={4}
            value={step.description}
          />

          <StepImageField index={index} step={step} onChange={(patch) => updateStep(index, patch)} />

          <input
            aria-label={`Vidéo (URL) de l'étape ${index + 1}`}
            className="min-h-9 w-full rounded border border-border bg-background px-2 text-sm outline-none focus:border-accent"
            onChange={(e) => updateStep(index, { videoUrl: e.target.value })}
            placeholder="Vidéo YouTube (URL, optionnel)"
            type="url"
            value={step.videoUrl ?? ""}
          />

        </div>
      ))}

      <button
        className="inline-flex w-fit items-center gap-2 rounded border border-border px-3 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        onClick={addStep}
        type="button"
      >
        <Plus aria-hidden="true" className="size-4" /> Ajouter une étape
      </button>
    </div>
  );
}

function StepImageField({
  index,
  step,
  onChange,
}: {
  index: number;
  step: InstallStep;
  onChange: (patch: Partial<InstallStep>) => void;
}) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /**
   * Uploads then appends `![](url)` to the description (story 31.11). There is no image field any
   * more: the markdown holds it, so an author can place several images and move them where they
   * want. The upload endpoint returns a stable URL - a presigned one would expire inside content.
   */
  async function handleFile(file: File | undefined) {
    if (!file) return;
    setUploading(true);
    setError(null);
    const result = await uploadTutorialImage(file);
    setUploading(false);
    if (result === null) {
      setError("Échec de l'envoi : format non supporté (JPEG, PNG, WebP, GIF) ou image > 10 Mo.");
      return;
    }

    const current = step.description;
    const separator = current.trim() === "" ? "" : "\n\n";
    onChange({ description: `${current}${separator}![](${result.url})` });
  }

  return (
    <div className="grid gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <label className="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent">
          {uploading ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : <ImagePlus aria-hidden="true" className="size-4" />}
          {uploading ? "Envoi…" : "Téléverser une image"}
          <input
            accept="image/png,image/jpeg,image/webp,image/gif"
            aria-label={`Téléverser une image pour l'étape ${index + 1}`}
            className="sr-only"
            disabled={uploading}
            onChange={(e) => void handleFile(e.target.files?.[0])}
            type="file"
          />
        </label>
        <span className="text-xs text-muted-foreground">
          L&apos;image est ajoutée à la description ; déplace-la où tu veux dans le texte.
        </span>
      </div>
      {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
    </div>
  );
}

function IconButton({
  label,
  onClick,
  disabled = false,
  children,
}: {
  label: string;
  onClick: () => void;
  disabled?: boolean;
  children: React.ReactNode;
}) {
  return (
    <button
      aria-label={label}
      className="inline-flex size-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}
