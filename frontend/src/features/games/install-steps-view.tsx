"use client";

import { useEffect, useState } from "react";

import type { GameStep } from "./public-games-api";
import { Markdown } from "@/components/markdown/markdown";
import { VideoEmbed } from "@/components/markdown/video-embed";

/**
 * Read-only render of an ordered list of install steps (story 31.1/31.3/31.5). Descriptions are
 * markdown since story 10.10, rendered through `Markdown` - which emits React elements, so raw HTML
 * in a description stays inert text. Links/media URLs are http(s) (validated server-side). When a
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
        // eslint-disable-next-line react-hooks/set-state-in-effect -- localStorage hydration on mount (client-only source, cannot be read during render)
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
          <li className="grid gap-2 rounded-lg border border-border bg-surface p-4" key={step.title}>
            <div className="flex items-start gap-2">
              {lsKey !== null ? (
                // Centred inside a box matching the title's first line (text-base/leading-tight = 1.25rem)
                // so it reads as aligned with the heading rather than sitting below it.
                <span className="flex h-5 shrink-0 items-center">
                  <input
                    aria-label={`Marquer « ${step.title} » comme fait`}
                    checked={checked}
                    className="size-4 accent-[color:var(--color-accent)]"
                    onChange={() => toggle(step.title)}
                    type="checkbox"
                  />
                </span>
              ) : (
                <span className="mt-[-0.125rem] flex size-6 shrink-0 items-center justify-center rounded-full bg-accent/15 text-xs font-semibold text-accent-text">
                  {index + 1}
                </span>
              )}
              <h3 className={`font-heading font-semibold leading-tight text-foreground ${checked ? "line-through opacity-60" : ""}`}>
                {step.title}
              </h3>
            </div>
            {step.description ? (
              <Markdown className="text-base leading-7 text-body-foreground">{step.description}</Markdown>
            ) : null}

            {step.videoUrl ? <VideoEmbed title={`Vidéo : ${step.title}`} url={step.videoUrl} /> : null}
          </li>
        );
      })}
    </ol>
  );
}

