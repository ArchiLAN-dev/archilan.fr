"use client";

import { ChevronDown, ChevronUp, Plus, X } from "lucide-react";

export type InstallStepType = "acquire" | "apworld" | "client" | "yaml" | "connect" | "note";
export type InstallLink = { label: string; url: string | null };
export type InstallStep = {
  type: InstallStepType;
  title: string;
  description: string;
  links: InstallLink[];
  imageUrl?: string | null;
  videoUrl?: string | null;
};

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
    onChange([...steps, { type: "note", title: "", description: "", links: [] }]);
  }

  function updateLink(stepIndex: number, linkIndex: number, patch: Partial<InstallLink>) {
    updateStep(stepIndex, {
      links: steps[stepIndex].links.map((link, i) => (i === linkIndex ? { ...link, ...patch } : link)),
    });
  }

  return (
    <div className="grid gap-4">
      {steps.length === 0 ? (
        <p className="text-sm text-muted-foreground">Aucune étape. Ajoute une étape ou génère un brouillon.</p>
      ) : null}

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

          <textarea
            aria-label={`Description de l'étape ${index + 1}`}
            className="min-h-20 w-full rounded border border-border bg-background px-3 py-2 text-sm outline-none focus:border-accent"
            onChange={(e) => updateStep(index, { description: e.target.value })}
            placeholder="Description (texte)"
            value={step.description}
          />

          <div className="grid gap-2 sm:grid-cols-2">
            <input
              aria-label={`Image (URL) de l'étape ${index + 1}`}
              className="min-h-9 w-full rounded border border-border bg-background px-2 text-sm outline-none focus:border-accent"
              onChange={(e) => updateStep(index, { imageUrl: e.target.value })}
              placeholder="Image (URL, optionnel)"
              type="url"
              value={step.imageUrl ?? ""}
            />
            <input
              aria-label={`Vidéo (URL) de l'étape ${index + 1}`}
              className="min-h-9 w-full rounded border border-border bg-background px-2 text-sm outline-none focus:border-accent"
              onChange={(e) => updateStep(index, { videoUrl: e.target.value })}
              placeholder="Vidéo YouTube (URL, optionnel)"
              type="url"
              value={step.videoUrl ?? ""}
            />
          </div>

          <div className="grid gap-2">
            {step.links.map((link, linkIndex) => (
              <div className="flex flex-wrap items-center gap-2" key={linkIndex}>
                <input
                  aria-label="Libellé du lien"
                  className="min-h-9 flex-1 rounded border border-border bg-background px-2 text-sm outline-none focus:border-accent"
                  onChange={(e) => updateLink(index, linkIndex, { label: e.target.value })}
                  placeholder="Libellé"
                  type="text"
                  value={link.label}
                />
                <input
                  aria-label="URL du lien"
                  className="min-h-9 flex-1 rounded border border-border bg-background px-2 text-sm outline-none focus:border-accent"
                  onChange={(e) => updateLink(index, linkIndex, { url: e.target.value })}
                  placeholder="https://… (optionnel)"
                  type="url"
                  value={link.url ?? ""}
                />
                <IconButton
                  label="Supprimer le lien"
                  onClick={() => updateStep(index, { links: step.links.filter((_, i) => i !== linkIndex) })}
                >
                  <X aria-hidden="true" className="size-4" />
                </IconButton>
              </div>
            ))}
            <button
              className="inline-flex w-fit items-center gap-1.5 text-sm font-semibold text-accent-text hover:underline"
              onClick={() => updateStep(index, { links: [...step.links, { label: "", url: "" }] })}
              type="button"
            >
              <Plus aria-hidden="true" className="size-3.5" /> Ajouter un lien
            </button>
          </div>
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
