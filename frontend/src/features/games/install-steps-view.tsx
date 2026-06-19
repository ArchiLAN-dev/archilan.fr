"use client";

import { useEffect, useState } from "react";
import { ExternalLink } from "lucide-react";

import type { GameStep } from "./public-games-api";

const STEP_TYPE_LABELS: Record<GameStep["type"], string> = {
  acquire: "Se procurer le jeu",
  apworld: "Apworld",
  client: "Client / patcher",
  yaml: "Configuration YAML",
  connect: "Connexion",
  note: "Note",
};

/**
 * Read-only render of an ordered list of install steps (story 31.1/31.3/31.5). Descriptions are
 * plain text (never raw HTML); links/media URLs are http(s) (validated server-side). When a
 * `storageKey` is given, each step gets a checkbox whose state is kept in localStorage (story 31.5),
 * so a player can track their install progress (no account needed).
 */
export function InstallStepsView({ steps, storageKey }: { steps: GameStep[]; storageKey?: string }) {
  // Progress is keyed by step title (not index) so reordering/inserting steps doesn't mis-tick.
  const [done, setDone] = useState<Set<string>>(new Set());
  const lsKey = storageKey ? `archilan.install-progress.${storageKey}` : null;

  useEffect(() => {
    if (lsKey === null) return;
    try {
      const raw = window.localStorage.getItem(lsKey);
      const parsed: unknown = raw === null ? [] : JSON.parse(raw);
      if (Array.isArray(parsed)) {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setDone(new Set(parsed.filter((t): t is string => typeof t === "string")));
      }
    } catch {
      // ignore corrupt storage
    }
  }, [lsKey]);

  function toggle(title: string) {
    if (lsKey === null) return;
    setDone((prev) => {
      const next = new Set(prev);
      if (next.has(title)) next.delete(title);
      else next.add(title);
      try {
        window.localStorage.setItem(lsKey, JSON.stringify([...next]));
      } catch {
        // ignore quota/availability errors
      }
      return next;
    });
  }

  return (
    <ol className="grid gap-4">
      {steps.map((step, index) => {
        const checked = done.has(step.title);
        return (
          <li className="grid gap-2 rounded-lg border border-border bg-surface p-4" key={index}>
            <div className="flex items-center gap-2">
              {lsKey !== null ? (
                <input
                  aria-label={`Marquer « ${step.title} » comme fait`}
                  checked={checked}
                  className="size-4 shrink-0 accent-[color:var(--color-accent)]"
                  onChange={() => toggle(step.title)}
                  type="checkbox"
                />
              ) : (
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent/15 text-xs font-semibold text-accent-text">
                  {index + 1}
                </span>
              )}
              <span className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                {STEP_TYPE_LABELS[step.type]}
              </span>
            </div>

            <h3 className={`font-heading font-semibold leading-tight text-foreground ${checked ? "line-through opacity-60" : ""}`}>
              {step.title}
            </h3>
            {step.description ? (
              <p className="whitespace-pre-line text-sm leading-7 text-muted-foreground">{step.description}</p>
            ) : null}

            {step.imageUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img alt={step.title} className="max-h-80 w-auto rounded border border-border" loading="lazy" src={step.imageUrl} />
            ) : null}

            {step.videoUrl ? <StepVideo url={step.videoUrl} /> : null}

            {step.links.length > 0 ? (
              <ul className="grid gap-1.5">
                {step.links.map((link, linkIndex) =>
                  link.url !== null ? (
                    <li key={`${link.label}-${linkIndex}`}>
                      <a
                        className="inline-flex items-center gap-2 text-accent-text underline-offset-2 hover:underline"
                        href={link.url}
                        rel="noopener noreferrer"
                        target="_blank"
                      >
                        {link.label}
                        <ExternalLink aria-hidden="true" className="size-3.5" />
                      </a>
                    </li>
                  ) : (
                    <li className="text-sm text-muted-foreground" key={`${link.label}-${linkIndex}`}>
                      {link.label}
                    </li>
                  ),
                )}
              </ul>
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

function youtubeId(url: string): string | null {
  const match = url.match(
    /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^&]*&)*v=|embed\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/,
  );
  return match ? match[1] : null;
}

/** Embeds a YouTube video safely (sandboxed, nocookie); other URLs fall back to a plain link. */
function StepVideo({ url }: { url: string }) {
  const id = youtubeId(url);
  if (id === null) {
    return (
      <a
        className="inline-flex w-fit items-center gap-2 text-accent-text underline-offset-2 hover:underline"
        href={url}
        rel="noopener noreferrer"
        target="_blank"
      >
        Voir la vidéo
        <ExternalLink aria-hidden="true" className="size-3.5" />
      </a>
    );
  }

  return (
    <div className="aspect-video w-full max-w-xl overflow-hidden rounded border border-border">
      <iframe
        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowFullScreen
        className="h-full w-full"
        referrerPolicy="strict-origin-when-cross-origin"
        sandbox="allow-scripts allow-same-origin allow-presentation"
        src={`https://www.youtube-nocookie.com/embed/${id}`}
        title="Vidéo du tutoriel"
      />
    </div>
  );
}
